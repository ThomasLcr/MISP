// An analyst graph's own page (AnalystGraphs/view.ctp): the graph mounted at
// full size through IntelGraph, the bar that says what is not saved yet, and
// the fork and settings dialogs.
//
// What a save keeps: positions and pins, nodes kept from a pivot, nodes
// removed, links hidden in this graph. Adds made from other pages are saved
// as they land and reach this page in place. A save on a version saved
// elsewhere since is refused (409); the bar offers to lay this canvas over
// the newer version, or to reload it.

(function () {
    'use strict';

    // assetLoader puts this script above the markup it reads.
    function start() {
        var config = JSON.parse(document.getElementById('ig-page-config').textContent);
        var graph = config.graph;
        var canEdit = !!config.canEdit;
        var handle = null;
        var saving = false;
        var conflict = null;
        var savedAt = graph.modified;
        var changes = {};

        function find(sel) { return document.querySelector(sel); }
        function el(tag, cls, text) {
            var e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text != null) e.textContent = text;
            return e;
        }
        function icon(cls) {
            var i = el('i', cls);
            i.setAttribute('aria-hidden', 'true');
            return i;
        }
        function baseurl() { return (window.IntelGraphConfig && window.IntelGraphConfig.baseurl) || ''; }
        function message(err) {
            var body = err && err.body;
            if (body && body.errors) {
                if (typeof body.errors === 'string') return body.errors;
                var first = Object.keys(body.errors)[0];
                var v = body.errors[first];
                return Array.isArray(v) ? v[0] : String(v);
            }
            return (err && err.message) || 'error';
        }
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

        function ago(datetime) {
            var t = Date.parse(String(datetime).replace(' ', 'T'));
            if (isNaN(t)) return '';
            var s = Math.round((Date.now() - t) / 1000);
            if (s < 45) return 'just now';
            var m = Math.round(s / 60);
            if (m < 60) return m + ' min ago';
            var h = Math.round(m / 60);
            if (h < 48) return h + ' h ago';
            return new Date(t).toLocaleDateString();
        }

        /* ── the bar ───────────────────────────────────────────── */
        var statusEl = find('[data-ig-page-status]');
        var saveBtn = find('[data-ig-page-save]');
        var discardBtn = find('[data-ig-page-discard]');
        var keepBtn = find('[data-ig-page-keep]');
        var hiddenWrap = find('[data-ig-page-hidden-wrap]');
        var hiddenMenu = find('[data-ig-page-hidden-menu]');
        var conflictEl = find('[data-ig-page-conflict]');

        var REASONS = {
            moved: 'moved', kept: 'kept from a pivot', removed: 'removed',
            hidden: 'links hidden', shown: 'links shown again'
        };

        function dirty() { return !!handle && handle.isDirty(); }

        function renderStatus() {
            statusEl.textContent = '';
            if (!handle) return;
            if (saving) {
                statusEl.appendChild(el('span', 'spinner-border spinner-border-sm me-1'));
                statusEl.appendChild(document.createTextNode('Saving…'));
            } else if (dirty() && canEdit) {
                statusEl.appendChild(icon('fas fa-circle text-warning me-1'));
                var what = Object.keys(changes).map(function (k) { return REASONS[k] || k; });
                statusEl.appendChild(el('strong', null, 'Not saved'));
                statusEl.appendChild(document.createTextNode(what.length ? ': ' + what.join(', ') + '.' : '.'));
            } else {
                statusEl.appendChild(icon('fas fa-check text-success me-1'));
                statusEl.appendChild(document.createTextNode('Saved ' + ago(savedAt) + ' · revision ' + handle.revision()));
            }
            if (saveBtn) saveBtn.disabled = saving || !dirty() || !!conflict;
            if (discardBtn) discardBtn.hidden = saving || !dirty();
        }

        function renderPivoted() {
            var n = handle ? handle.pivoted().length : 0;
            keepBtn.hidden = !canEdit || !n;
            find('[data-ig-page-keep-label]').textContent = 'Keep ' + plural(n, 'pivoted-in node', 'pivoted-in nodes');
        }

        function nodeLabel(id) {
            var g = handle && handle.graph();
            var n = g && g.getMutableNode(id);
            var d = n && n.getData ? n.getData() : null;
            return (d && (d.label || d.value)) || id;
        }

        function renderHidden() {
            var list = handle ? handle.hiddenEdges() : [];
            hiddenWrap.hidden = !list.length;
            find('[data-ig-page-hidden-label]').textContent = plural(list.length, 'hidden link', 'hidden links');
            hiddenMenu.textContent = '';
            hiddenMenu.appendChild(el('h6', 'dropdown-header', 'Hidden in this graph'));
            list.forEach(function (e) {
                var row = el('div', 'd-flex align-items-center gap-2 px-3 py-1');
                row.setAttribute('data-ig-edge', e.id);
                var text = el('span', 'small text-truncate flex-grow-1');
                text.textContent = nodeLabel(e.from) + ' → ' + ((e.data && e.data.label) || e.data.kind || 'link') + ' → ' + nodeLabel(e.to);
                text.title = text.textContent;
                row.appendChild(text);
                if (canEdit) {
                    var show = el('button', 'btn btn-sm btn-outline-secondary py-0', 'Show');
                    show.type = 'button';
                    show.addEventListener('click', function () { handle.showEdge(e.id); });
                    row.appendChild(show);
                }
                hiddenMenu.appendChild(row);
            });
        }

        function render() {
            renderStatus();
            renderPivoted();
            renderHidden();
        }

        function onChange(h, reason) {
            if (reason && canEdit) changes[reason] = true;
            render();
        }

        /* ── saving ────────────────────────────────────────────── */
        function showConflict(revision) {
            conflict = { revision: revision };
            conflictEl.hidden = false;
            conflictEl.textContent = '';
            conflictEl.appendChild(el('strong', null, 'Not saved: this graph was saved elsewhere since it opened here'
                + (revision != null ? ' (it is now at revision ' + revision + ')' : '') + '. '));
            conflictEl.appendChild(document.createTextNode('Save your changes over that version — what it added is kept — or reload it and drop them.'));
            var acts = el('div', 'mt-2 d-flex gap-2');
            var over = el('button', 'btn btn-sm btn-danger', 'Save my changes over it');
            over.type = 'button';
            over.addEventListener('click', function () {
                over.disabled = true;
                handle.rebase().then(function () {
                    conflict = null;
                    conflictEl.hidden = true;
                    save();
                }, function (err) {
                    over.disabled = false;
                    conflictEl.appendChild(el('div', 'mt-1', 'Could not read the newer version: ' + message(err)));
                });
            });
            var reload = el('button', 'btn btn-sm btn-outline-danger', 'Reload the graph');
            reload.type = 'button';
            reload.addEventListener('click', function () { window.removeEventListener('beforeunload', guard); window.location.reload(); });
            acts.appendChild(over);
            acts.appendChild(reload);
            conflictEl.appendChild(acts);
            render();
        }

        function save() {
            if (!handle || saving) return;
            saving = true;
            render();
            handle.save().then(function (report) {
                saving = false;
                changes = {};
                savedAt = report.modified || savedAt;
                render();
            }, function (err) {
                saving = false;
                if (err && err.status === 409) {
                    showConflict(err.body && err.body.revision);
                    return;
                }
                render();
                statusEl.appendChild(el('div', 'text-danger', 'Not saved: ' + message(err)));
            });
        }

        function guard(e) {
            if (!dirty() || !canEdit) return;
            e.preventDefault();
            e.returnValue = '';
        }

        /* ── forking ───────────────────────────────────────────── */
        function modal(id) {
            var m = document.getElementById(id);
            return m && window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(m) : null;
        }

        window.igGraphPageFork = function () {
            var form = find('[data-ig-page-fork-form]');
            var list = form.querySelector('[data-ig-page-fork-targets]');
            var error = form.querySelector('[data-ig-page-fork-error]');
            form.querySelector('#ig-page-fork-name').value = graph.name;
            error.textContent = '';
            list.textContent = '';
            if (!config.forkTargets.length) {
                list.appendChild(el('p', 'small text-body-secondary', 'Your organisation has no collection to attach a fork to. Open the event, collection or galaxy cluster your copy should hang off, and fork the graph from its dock there.'));
            }
            config.forkTargets.forEach(function (t, i) {
                var wrap = el('div', 'form-check');
                var input = el('input', 'form-check-input');
                input.type = 'radio';
                input.name = 'ig-fork-target';
                input.id = 'ig-fork-target-' + i;
                input.value = String(i);
                input.checked = i === 0;
                var label = el('label', 'form-check-label', (config.targetTypes[t.type] || t.type) + ' · ' + t.label);
                label.htmlFor = input.id;
                if (i === 0 && graph.target && t.uuid === graph.target.uuid) {
                    label.appendChild(el('span', 'badge text-bg-secondary ms-2', 'this graph’s'));
                }
                wrap.appendChild(input);
                wrap.appendChild(label);
                list.appendChild(wrap);
            });
            form.querySelector('[type="submit"]').disabled = !config.forkTargets.length;
            var m = modal('ig-page-fork');
            if (m) m.show();
        };

        function bindFork() {
            var form = find('[data-ig-page-fork-form]');
            if (!form) return;
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var error = form.querySelector('[data-ig-page-fork-error]');
                var picked = form.querySelector('input[name="ig-fork-target"]:checked');
                var name = form.querySelector('#ig-page-fork-name').value.trim();
                if (!name) { error.textContent = 'A graph needs a name.'; return; }
                if (!picked) { error.textContent = 'Pick what the fork attaches to.'; return; }
                var t = config.forkTargets[+picked.value];
                var submit = form.querySelector('[type="submit"]');
                submit.disabled = true;
                window.IntelGraph.fork(graph.uuid, { name: name, target: { type: t.type, uuid: t.uuid } }).then(function (fork) {
                    window.removeEventListener('beforeunload', guard);
                    window.location.href = baseurl() + '/analyst_graphs/view/' + encodeURIComponent(fork.uuid);
                }, function (err) {
                    submit.disabled = false;
                    error.textContent = 'Could not fork: ' + message(err);
                });
            });
        }

        /* ── settings ──────────────────────────────────────────── */
        function sharing() {
            return (config.explorer && config.explorer.analystSharing) || { levels: [], sharingGroups: [] };
        }

        window.igGraphPageSettings = function () {
            var form = find('[data-ig-page-settings-form]');
            form.querySelector('#ig-page-settings-name').value = graph.name;
            form.querySelector('#ig-page-settings-description').value = graph.description || '';
            var dist = form.querySelector('#ig-page-settings-distribution');
            var sg = form.querySelector('#ig-page-settings-sg');
            dist.textContent = '';
            sharing().levels.forEach(function (l) {
                var o = el('option', null, l[1]);
                o.value = String(l[0]);
                o.selected = l[0] === graph.distribution;
                dist.appendChild(o);
            });
            sg.textContent = '';
            sharing().sharingGroups.forEach(function (g) {
                var o = el('option', null, g[1]);
                o.value = String(g[0]);
                o.selected = g[0] === graph.sharing_group_id;
                sg.appendChild(o);
            });
            sg.hidden = dist.value !== '4';
            form.querySelector('[data-ig-page-settings-error]').textContent = '';
            var m = modal('ig-page-settings');
            if (m) m.show();
        };

        function bindSettings() {
            var form = find('[data-ig-page-settings-form]');
            if (!form) return;
            var dist = form.querySelector('#ig-page-settings-distribution');
            var sg = form.querySelector('#ig-page-settings-sg');
            var error = form.querySelector('[data-ig-page-settings-error]');
            dist.addEventListener('change', function () { sg.hidden = dist.value !== '4'; });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var name = form.querySelector('#ig-page-settings-name').value.trim();
                if (!name) { error.textContent = 'A graph needs a name.'; return; }
                var body = { Graph: {
                    name: name,
                    description: form.querySelector('#ig-page-settings-description').value,
                    distribution: +dist.value,
                    sharing_group_id: dist.value === '4' ? +sg.value : null
                } };
                if (dist.value === '4' && !sg.value) { error.textContent = 'Pick the sharing group to share it with.'; return; }
                window.IntelGraph.request('POST', '/analystData/edit/Graph/' + graph.id + '.json', body).then(function () {
                    window.removeEventListener('beforeunload', guard);
                    window.location.reload();
                }, function (err) {
                    error.textContent = 'Not saved: ' + message(err);
                });
            });
            form.querySelector('[data-ig-page-delete]').addEventListener('click', function () {
                if (!window.confirm('Delete “' + graph.name + '”? The records it shows stay in MISP; the graph and its layout are gone, here and wherever it was shared.')) return;
                window.IntelGraph.request('POST', '/analystData/delete/Graph/' + graph.id + '.json', {}).then(function () {
                    window.removeEventListener('beforeunload', guard);
                    var t = graph.target;
                    window.location.href = baseurl() + (t && t.id ? config.targetPaths[t.type] + t.id : '/analystData/index/Graph');
                }, function (err) {
                    error.textContent = 'Not deleted: ' + message(err);
                });
            });
        }

        /* ── boot ──────────────────────────────────────────────── */
        function boot() {
            bindFork();
            bindSettings();
            if (saveBtn) saveBtn.addEventListener('click', save);
            if (discardBtn) discardBtn.addEventListener('click', function () {
                window.removeEventListener('beforeunload', guard);
                window.location.reload();
            });
            keepBtn.addEventListener('click', function () { if (handle) handle.keep(handle.pivoted()); });
            window.addEventListener('beforeunload', guard);
            var container = document.getElementById('ig-page-graph');
            window.IntelGraph.mount(container, Object.assign({}, canEdit ? config.explorer : { labelPlan: config.explorer.labelPlan, permitted: config.explorer.permitted }, {
                graph: graph.uuid,
                loaderEl: document.getElementById('ig-page-loader'),
                cardEl: document.getElementById('ig-page-card'),
                fitHeight: true,
                menus: canEdit,
                onChange: onChange
            })).then(function (h) {
                handle = h;
                window.IntelGraphPage = { handle: function () { return handle; }, save: save };
                return h.ready;
            }).then(function (h) {
                try { h.graph().onVisibleChange(function () { renderPivoted(); renderHidden(); }); } catch (e) { /* counted on edits only */ }
                render();
            }).catch(function (err) {
                statusEl.textContent = 'The graph could not be drawn: ' + message(err);
            });
        }

        boot();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
