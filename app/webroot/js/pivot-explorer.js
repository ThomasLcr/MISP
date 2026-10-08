// Pivot Explorer — the object-reference graph on the event view's
// "Pivot Explorer" tab (Elements/Events/View/event_pivot_explorer.ctp), and the
// factory other graphs of MISP records build on (window.MispPivotExplorer).
//
// The element owns the markup and the server-side values; this file owns all
// behaviour. The event page's config is read off #pe-card's data-* attributes:
//
//   [data-pe-event-id]     event fetched as /events/view/{id}/includeServerCorrelations:1.json
//   [data-pe-baseurl]      MISP $baseurl, prefixed onto every request
//   [data-pe-can-edit]     "1" when the viewer may add object references
//   [data-pe-value-card]   "1" when MISP.value_hover_card is on
//   [data-pe-can-enrich]   "1" when the viewer may run enrichment modules
//   [data-pe-lib-missing]  translated error: pivotick failed to load
//   [data-pe-load-failed]  translated error: the event fetch failed
//
// Requires window.Pivotick (pivotick.iife.js) and window.MispPivotNodes
// (misp-pivot-nodes.js, the node drawings); the element's assetLoader call
// pulls in all three.

(function () {
    'use strict';

    // One explorer per host. The event page's host is read off #pe-card
    // (boot, below); another page hands its own, with any of these hooks:
    //
    //   load(kit)                 → Promise<{ data, event? }>, the seed
    //   pivots(kit)               → the pivot list
    //   options(opts, kit, seed)  → adjusts the pivotick options
    //   afterMount(graph, kit, seed)
    //   provenance: false         → no "this event" against "elsewhere"
    //   origins: { id: {...} }    → a merged extension view: a corner badge on
    //                               each node of event `id`, in its { color },
    //                               naming it by { title }, glyph by { role }
    //   chips: false              → every zoom keeps the small drawing
    //   canReference(from, to)    → whether a drawn edge between these node
    //                               data can be an object reference
    //
    // A hook left out keeps the event page's behaviour.
    function createExplorer(host) {
        /* ── config (from the host) ───────────────────────────── */
        var cfg     = host.config || {};
        var eventId = cfg.eventId || '';
        var baseurl = cfg.baseurl || '';
        var canEdit = !!cfg.canEdit;
        var text    = Object.assign({ libMissing: 'Graph library failed to load.',
                                      loadFailed: 'Failed to load event graph.' }, cfg.text);
        // Analyst relationships are gated on role alone, not on the event (D8);
        // deleting one needs its creator org, or site admin.
        var canAnalyst = !!cfg.canAnalyst;
        var orgUuid    = cfg.orgUuid || '';
        var siteAdmin  = !!cfg.siteAdmin;
        // What an analyst relationship can be shared with: [[level, name]],
        // [[sharing group id, name]], the default level and the user's email.
        var analystSharing = Object.assign({ levels: [], sharingGroups: [], default: 1, authors: '' },
                                           cfg.analystSharing);
        // Per object template ('uuid.version'), the ui-priority of each relation.
        var uiPriorities = cfg.uiPriorities || {};
        // The viewer's analyst profile as ValueLabelPriority::planFor() gives it,
        // and which of its pinned taxonomies and galaxies the instance enables.
        var labelPlan = cfg.labelPlan || null;
        var permitted = cfg.permitted || null;
        // MISP.value_hover_card: a value in the sidebar opens its hover card.
        var valueCard = !!cfg.valueCard;
        // perm_add: the Enrich pivot is offered at all (E11).
        var canEnrich = !!cfg.canEnrich;
        // "This event" against "elsewhere": only a graph of one event has a self.
        var hasProvenance = host.provenance !== false;
        var hasChips = host.chips !== false;

        /* ── state ─────────────────────────────────────────────── */
        var _initialized = false;
        var _ready       = null;
        var _graph       = null;
        var _event       = null;

        /* ── helpers ───────────────────────────────────────────── */
        // data-misp-mode on <html> is dark under Overmind's dark toggle and dark-only themes.
        function mispTheme() {
            return document.documentElement.getAttribute('data-misp-mode') === 'dark' ? 'dark' : 'light';
        }

        function followMispTheme(graph, root) {
            new MutationObserver(function () {
                var theme = mispTheme();
                if (root.getAttribute('data-theme') === theme) return;
                root.setAttribute('data-theme', theme);
                window.MispPivotNodes.applyTheme(graph, theme);
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-misp-mode'] });
        }

        function truncate(str, max) {
            str = String(str == null ? '' : str);
            return str.length > max ? str.substring(0, max - 1) + '…' : str;
        }

        // Screenshots and other pictures are stored as `attachment` attributes whose
        // value is an image filename — mirror MispAttribute::isImage() server-side.
        function isImageAttribute(attr) {
            if (!attr || attr.type !== 'attachment') return false;
            return /\.(jpe?g|png|gif|webp)$/i.test(String(attr.value == null ? '' : attr.value));
        }

        // Thumbnail served by AttributesController::viewPicture() (ACL-checked,
        // accepts the attribute UUID). The webp variant is generated at 400px
        // (vs 200px for png) and cached, so the framed picture stays crisp.
        function attributeImageUrl(attr) {
            return baseurl + '/attributes/viewPicture/' + attr.uuid + '/webp';
        }

        // Which event a node belongs to, and whether it is the one the graph was
        // seeded from. Read by the Provenance facet and the sidebar, never drawn:
        // the analyst decides which event is the subject.
        function provenance(id, uuid) {
            return {
                scope:      String(id) === String(eventId) ? 'self' : 'foreign',
                event_id:   id,
                event_uuid: uuid
            };
        }

        // The owner of a record in an event payload. Extension events merge their
        // elements in with only an id to tell them apart.
        function ownerIn(ev, rec) {
            var id = (rec && rec.event_id != null) ? rec.event_id : ev.id;
            return provenance(id, String(id) === String(ev.id) ? ev.uuid : undefined);
        }

        // Shared by the graph builder and the pivots so an attribute brought in
        // renders identically to one that was referenced from the start.
        function attributeNodeData(attr, owner) {
            var val   = attr.value != null ? String(attr.value) : '';
            var isImg = isImageAttribute(attr);
            return Object.assign({
                type:            'attribute',
                label:           val,
                description:     (attr.object_relation ? attr.object_relation + ' · ' : '')
                                 + (attr.category || '') + (attr.type ? ' / ' + attr.type : ''),
                value:           val,
                'attr-type':     attr.type,
                category:        attr.category,
                object_relation: attr.object_relation,
                to_ids:          attr.to_ids,
                comment:         attr.comment,
                uuid:            attr.uuid,
                image:           isImg || undefined,
                imageUrl:        isImg ? attributeImageUrl(attr) : undefined,
                feed_hit:        attr.FeedHit ? true : undefined
            }, warningFields(attr), tagFields(attr), owner, analystFields(attr));
        }

        // The warninglists an attribute's value is on, as MISP's event payload
        // names them. Only to_ids attributes are checked, unless the instance
        // checks every attribute.
        function warningFields(attr) {
            var seen = {};
            var out = (attr.warnings || []).filter(function (w) {
                if (!w || w.warninglist_id == null || seen[w.warninglist_id]) return false;
                return (seen[w.warninglist_id] = true);
            }).map(function (w) {
                return { warninglist_id: String(w.warninglist_id), warninglist_name: w.warninglist_name,
                         warninglist_category: w.warninglist_category, match: w.match };
            });
            return { warnings: out.length ? out : undefined, warninglisted: out.length > 0 };
        }

        // An object's attribute, ranked by its template: the node drawing leads
        // with the highest ui_priority.
        function objectChildData(obj, attr, owner) {
            var ranks = uiPriorities[obj.template_uuid + '.' + obj.template_version];
            var data = attributeNodeData(attr, owner);
            if (ranks && ranks[attr.object_relation]) data.ui_priority = ranks[attr.object_relation];
            return data;
        }

        // Soft-deleted records (deleted=1) are tombstones — refs create no edge and
        // don't count a node as connected; deleted attributes/objects aren't shown.
        function isDeleted(rec) {
            return !!rec && (rec.deleted === true || rec.deleted === 1 || rec.deleted === '1');
        }

        // Node id an analyst relationship's target maps to, or null when it has no
        // node on this canvas. AnalystData::valid_targets is far wider than the
        // canvas — EventReport, Organisation, SharingGroup and the analyst-data
        // types are all legal targets. An 'Event' target is drawn as an event node,
        // from the record the payload attaches (relationshipFarEnd); a
        // GalaxyCluster is mapped by relationshipEndId.
        function analystNodeId(type, uuid) {
            var t = String(type || '');
            if (t === 'Attribute') return 'attr:'  + uuid;
            if (t === 'Object')    return 'obj:'   + uuid;
            if (t === 'Event')     return 'event:' + uuid;
            return null;
        }

        // A galaxy cluster lands on the node the tags pivot draws for it, which is
        // keyed by tag name, so only a far end MISP resolved can name it.
        function relationshipEndId(rel, type, uuid) {
            if (String(type || '') !== 'GalaxyCluster') return analystNodeId(type, uuid);
            var c = (rel.related_object || {}).GalaxyCluster;
            if (!c || String(c.uuid) !== String(uuid) || !c.tag_name || isDeleted(c)) return null;
            return clusterNodeId(c);
        }

        function analystTargetId(rel) {
            return relationshipEndId(rel, rel.related_object_type, rel.related_object_uuid);
        }

        // The far end of a relationship — its target when outbound, its source
        // when inbound — as MISP attaches it in `related_object`, read with the
        // viewer's access. Null when the viewer cannot read it, which leaves the
        // relationship undrawable. An attribute or object carries its event and
        // that event's creator org (Relationship::rearrangeData).
        function relationshipFarEnd(rel, farId) {
            var ro = rel.related_object || {};
            if (farId.indexOf('cluster:') === 0) {
                return ro.GalaxyCluster && clusterNodeId(ro.GalaxyCluster) === farId
                    ? { type: 'cluster', record: ro.GalaxyCluster } : null;
            }
            var uuid = farId.slice(farId.indexOf(':') + 1);
            var key = { event: 'Event', attr: 'Attribute', obj: 'Object' }[farId.slice(0, farId.indexOf(':'))];
            var rec = ro[key];
            if (!rec || String(rec.uuid) !== uuid || isDeleted(rec)) return null;
            if (key === 'Event') return { type: 'event', record: rec };
            if (!rec.Event || !rec.Event.uuid) return null;
            return {
                type:   key === 'Attribute' ? 'attribute' : 'object',
                record: rec,
                event:  Object.assign({}, rec.Event, { Orgc: rec.Organisation })
            };
        }

        // Walk every analyst relationship touching the event, calling
        // cb(relationship, fromId, toId, farId): outbound ones from the event
        // itself, event-level attributes, objects, and objects' child attributes,
        // and inbound ones pointing at any of those. farId names the end that is
        // not the element walked. A tombstoned owner is skipped whole, exactly as
        // it is on the canvas.
        function eachAnalystRelationship(ev, cb) {
            function walk(rec, selfId) {
                if (isDeleted(rec)) return;
                (rec.Relationship || []).forEach(function (rel) {
                    if (isDeleted(rel)) return;
                    var to = analystTargetId(rel);
                    cb(rel, selfId, to, to);
                });
                (rec.RelationshipInbound || []).forEach(function (rel) {
                    if (isDeleted(rel)) return;
                    var from = relationshipEndId(rel, rel.object_type, rel.object_uuid);
                    cb(rel, from, selfId, from);
                });
            }
            if (ev.uuid) walk(ev, 'event:' + ev.uuid);
            (ev.Attribute || []).forEach(function (a) { walk(a, 'attr:' + a.uuid); });
            (ev.Object || []).forEach(function (obj) {
                if (isDeleted(obj)) return;
                walk(obj, 'obj:' + obj.uuid);
                (obj.Attribute || []).forEach(function (a) { walk(a, 'attr:' + a.uuid); });
            });
        }

        // Single source of truth for "what is authored", read through the seed.
        // D5' seeds any element
        // participating in an object reference *or* an analyst relationship. An
        // object counts if it is an endpoint itself, OR owns a child attribute that
        // some live relationship touches.
        function computeConnectivity(ev) {
            var linkedAttrUuids   = {};
            var connectedObjUuids = {};
            var eventTouched      = {};   // event node id -> the record it draws from
            var attrOwner         = {};   // object child attr uuid -> owning object uuid
            var liveObj           = {};   // uuid -> true, for endpoint resolution
            var liveAttr          = {};
            var liveEvent         = {};   // event node id -> record; others join per relationship
            var liveForeign       = {};   // another event's attr/obj node id -> relationshipFarEnd
            var foreignTouched    = {};
            var liveCluster       = {};   // cluster node id -> relationshipFarEnd's record
            var clusterTouched    = {};

            if (ev.uuid) liveEvent['event:' + ev.uuid] = ev;

            (ev.Attribute || []).forEach(function (a) {
                if (!isDeleted(a)) liveAttr[a.uuid] = true;
            });
            (ev.Object || []).forEach(function (obj) {
                var live = !isDeleted(obj);
                if (live) liveObj[obj.uuid] = true;
                (obj.Attribute || []).forEach(function (a) {
                    attrOwner[a.uuid] = obj.uuid;
                    if (live && !isDeleted(a)) liveAttr[a.uuid] = true;
                });
            });

            // Does this node id name a live element of this event, or one of
            // another event's the payload let the viewer read? A relationship
            // whose other end is a tombstone, unreadable, or an element type the
            // canvas does not draw is not drawable — and an undrawable
            // relationship must seed neither end, or its source arrives as an
            // isolated node with no edge.
            function exists(id) {
                if (!id) return false;
                if (id.indexOf('event:') === 0) return !!liveEvent[id];
                if (id.indexOf('cluster:') === 0) return !!liveCluster[id];
                if (liveForeign[id]) return true;
                return id.indexOf('obj:') === 0
                    ? !!liveObj[id.slice(4)]
                    : !!liveAttr[id.slice(5)];
            }

            // Mark one endpoint, given the node id it would carry. A touched child
            // attribute pulls its owning object onto the canvas with it.
            function markEndpoint(id) {
                if (!id) return;
                if (id.indexOf('event:') === 0) {
                    eventTouched[id] = liveEvent[id];
                    return;
                }
                if (liveCluster[id]) {
                    clusterTouched[id] = liveCluster[id];
                    return;
                }
                if (liveForeign[id]) {
                    foreignTouched[id] = liveForeign[id];
                    return;
                }
                if (id.indexOf('obj:') === 0) {
                    connectedObjUuids[id.slice(4)] = true;
                    return;
                }
                var uuid = id.slice(5);
                linkedAttrUuids[uuid] = true;
                if (attrOwner[uuid]) connectedObjUuids[attrOwner[uuid]] = true;
            }

            (ev.Object || []).forEach(function (obj) {
                if (isDeleted(obj)) return;
                (obj.ObjectReference || []).forEach(function (ref) {
                    if (isDeleted(ref)) return;
                    var targetId = (String(ref.referenced_type) === '1' ? 'obj:' : 'attr:')
                                   + ref.referenced_uuid;
                    if (!exists(targetId)) return;
                    markEndpoint('obj:' + obj.uuid);
                    markEndpoint(targetId);
                });
            });

            eachAnalystRelationship(ev, function (rel, fromId, toId, farId) {
                if (!fromId || !toId || fromId === toId) {
                    return;   // self-reference; the model rejects these, guard anyway
                }
                if (!exists(farId)) {
                    var far = relationshipFarEnd(rel, farId);
                    if (far && far.type === 'event') liveEvent[farId] = far.record;
                    else if (far && far.type === 'cluster') liveCluster[farId] = far.record;
                    else if (far && String(far.event.uuid) !== String(ev.uuid)) liveForeign[farId] = far;
                }
                if (!exists(fromId) || !exists(toId)) return;
                markEndpoint(fromId);
                markEndpoint(toId);
            });

            return {
                linkedAttrUuids:   linkedAttrUuids,
                connectedObjUuids: connectedObjUuids,
                eventTouched:      eventTouched,
                foreignTouched:    foreignTouched,
                clusterTouched:    clusterTouched
            };
        }

        // Pivotick's own detail threshold: past 1,500 nodes the minimap stops
        // resolving per-node style and reads as a density map. D12 reuses it as the
        // seed budget, so the canvas never opens past the point of legibility.
        var NODE_BUDGET = 1500;

        // Whether a feed or server edge will be drawn into this attribute.
        function isSourceHit(a) {
            return !isDeleted(a) && SOURCES.some(function (s) { return (a[s.scope] || []).length > 0; });
        }

        function hasSourceHit(obj) {
            return (obj.Attribute || []).some(isSourceHit);
        }

        function liveChildCount(obj) {
            var n = 0;
            (obj.Attribute || []).forEach(function (a) { if (!isDeleted(a)) n++; });
            return n;
        }

        // The seed (D12). Decides which resolution levels this event affords and
        // which elements each one contributes, analytically — no nodes are built.
        // The canvas builder reads it; the element pivot offers whatever it left out.
        //
        //   L1  everything an object reference or analyst relationship touches
        //   L2  the remaining objects and event-level attributes a feed or
        //       server hits, if the whole set fits
        //
        // An element nothing links to would land with no edge, so it is left to
        // the element pivot.
        //
        // Correlations are not a level: they are fetched per element (R1), and the
        // Correlations tab already lists the events this one shares values with.
        //
        // L2 is all-or-nothing on purpose. D10 says that above the budget "the seed
        // stops at L1": a greedy partial fill would draw an arbitrary 40 of
        // 28,410 objects, with nothing to say which 40.
        function computeSeed(ev) {
            var conn = computeConnectivity(ev);

            // An event node is an analyst relationship's endpoint or nothing: a
            // bare hexagon would make the seed permanently non-empty and D11's
            // "nothing to draw" message unreachable.
            var eventNodes = Object.keys(conn.eventTouched).map(function (id) {
                return { id: id, record: conn.eventTouched[id] };
            });

            // Another event's attribute or object, with its event's card beside it.
            var foreignNodes = Object.keys(conn.foreignTouched).map(function (id) {
                return Object.assign({ id: id }, conn.foreignTouched[id]);
            });
            var cards = {};
            foreignNodes.forEach(function (f) {
                var cardId = 'event:' + f.event.uuid;
                if (!conn.eventTouched[cardId]) cards[cardId] = true;
            });

            var clusterNodes = Object.keys(conn.clusterTouched).map(function (id) {
                return { id: id, record: conn.clusterTouched[id] };
            });

            var l1 = eventNodes.length + foreignNodes.length + Object.keys(cards).length
                     + clusterNodes.length;
            (ev.Attribute || []).forEach(function (a) {
                if (!isDeleted(a) && conn.linkedAttrUuids[a.uuid]) l1++;
            });

            var l2Uuids = {}, l2AttrUuids = {};
            var l2Cost  = 0;   // nodes: an attribute, or an object plus its live children
            (ev.Attribute || []).forEach(function (a) {
                if (conn.linkedAttrUuids[a.uuid] || !isSourceHit(a)) return;
                l2AttrUuids[a.uuid] = true;
                l2Cost++;
            });
            (ev.Object || []).forEach(function (obj) {
                if (isDeleted(obj)) return;
                var cost = 1 + liveChildCount(obj);
                if (conn.connectedObjUuids[obj.uuid]) { l1 += cost; return; }
                if (!hasSourceHit(obj)) return;
                l2Uuids[obj.uuid] = true;
                l2Cost += cost;
            });

            var l2Fits = (l1 + l2Cost) <= NODE_BUDGET;

            return {
                linkedAttrUuids:   conn.linkedAttrUuids,
                connectedObjUuids: conn.connectedObjUuids,
                eventNodes:        eventNodes,
                foreignNodes:      foreignNodes,
                clusterNodes:      clusterNodes,
                // What L2 actually draws — empty when the level did not fit, so
                // "is this on the canvas?" is one lookup for every caller.
                l2Uuids:           l2Fits ? l2Uuids : {},
                l2AttrUuids:       l2Fits ? l2AttrUuids : {}
            };
        }

        // Galaxy clusters and non-galaxy tags, for the event card's context row.
        // A relationship's target event carries neither, and draws none.
        function eventContext(e) {
            var out = [];
            (e.Galaxy || []).forEach(function (g) {
                (g.GalaxyCluster || []).forEach(function (c) {
                    out.push({ galaxy_type: g.type, value: c.value });
                });
            });
            return out.length ? out : undefined;
        }

        // A galaxy cluster's tag, misp-galaxy:TYPE="VALUE".
        function galaxyTag(name) {
            var m = /^misp-galaxy:([^=]+)="(.*)"$/.exec(String(name || ''));
            return m ? { galaxy_type: m[1], value: m[2] } : null;
        }

        // An element's tags and galaxy clusters, as `tags` and `clusters`. A
        // cluster is keyed by its tag, which is how it is attached everywhere:
        // the Tag list names it even where Galaxy is missing or trimmed.
        function tagFields(rec) {
            var named = {};
            (rec.Galaxy || []).forEach(function (g) {
                (g.GalaxyCluster || []).forEach(function (c) {
                    if (c.tag_name) named[c.tag_name] = { galaxy_type: g.type, galaxy_name: g.name, value: c.value, uuid: c.uuid };
                });
            });
            var tags = [], clusters = [], seen = {};
            function cluster(tagName, local) {
                var n = named[tagName] || {}, g = galaxyTag(tagName) || {};
                clusters.push({ tag_name: tagName, galaxy_type: n.galaxy_type || g.galaxy_type,
                                galaxy_name: n.galaxy_name, value: n.value || g.value || tagName,
                                uuid: n.uuid, local: local || undefined });
            }
            (rec.Tag || []).forEach(function (t) {
                if (!t || !t.name || t.hide_tag || seen[t.name]) return;
                seen[t.name] = true;
                if (t.is_galaxy || galaxyTag(t.name)) { cluster(t.name, !!t.local); return; }
                tags.push({ name: t.name, colour: t.colour, local: t.local ? true : undefined,
                            relationship_type: t.relationship_type || undefined });
            });
            Object.keys(named).forEach(function (tagName) { if (!seen[tagName]) cluster(tagName, false); });
            return { tags: tags.length ? tags : undefined, clusters: clusters.length ? clusters : undefined };
        }

        function numberOr(v) {
            return (v == null || v === '') ? undefined : Number(v);
        }

        function eventNodeData(e) {
            var info = e.info != null ? String(e.info) : '';
            var orgc = e.Orgc || e.Org;
            var org  = (orgc && orgc.name) || '';
            var meta = [];
            if (e.date) meta.push(String(e.date));
            if (org)    meta.push(org);
            return Object.assign({
                type:        'event',
                label:       info || ('Event ' + (e.id || '')),
                description: meta.join(' · ') || 'Event',
                info:        info,
                date:        e.date,
                org:         org || undefined,
                uuid:        e.uuid,
                // Read by the event card (misp-pivot-nodes), not by the sidebar.
                id:                e.id,
                orgc:              orgc ? { name: orgc.name, uuid: orgc.uuid } : undefined,
                published:         e.published,
                publish_timestamp: numberOr(e.publish_timestamp) || undefined,
                distribution:      numberOr(e.distribution),
                attribute_count:   numberOr(e.attribute_count),
                object_count:      e.Object ? e.Object.filter(function (o) { return !isDeleted(o); }).length : numberOr(e.object_count),
                report_count:      e.EventReport ? e.EventReport.length : undefined,
                context:           eventContext(e)
            }, tagFields(e), provenance(e.id, e.uuid), analystFields(e));
        }

        // Shared object node data (graph builder + element pivot).
        function objectNodeData(obj, owner) {
            return Object.assign({
                type:            'object',
                label:           obj.name || 'Object',
                description:     obj['meta-category'] ? (obj['meta-category'] + ' object') : 'Object',
                name:            obj.name,
                'meta-category': obj['meta-category'],
                uuid:            obj.uuid
            }, owner, analystFields(obj));
        }

        // A feed or server this event's values were seen in: one node per source,
        // off the payload's deduplicated event.Feed / event.Server map. No `name`
        // key, which the Object facet reads. A restricted Server source carries
        // only id and name (Feed.php), so everything past the label is optional.
        var SOURCES = [
            { scope: 'Feed',   type: 'feed',   kind: 'feed-correlation' },
            { scope: 'Server', type: 'server', kind: 'server-correlation' }
        ];

        // The event's feeds or servers by id. Keyed by id when MISP builds it,
        // but serialised as a list, and a source can appear once per batch of
        // attributes MISP looked up, each naming only its share of the events.
        function sourceMap(ev, scope) {
            var known = {};
            Object.keys(ev[scope] || {}).forEach(function (k) {
                var src = ev[scope][k];
                if (!src || src.id == null) return;
                var id = String(src.id);
                if (!known[id]) known[id] = Object.assign({}, src, { event_uuids: undefined });
                (src.event_uuids || []).forEach(function (uuid) {
                    var list = known[id].event_uuids = known[id].event_uuids || [];
                    if (list.indexOf(uuid) === -1) list.push(uuid);
                });
            });
            return known;
        }

        function sourceNodeData(type, src) {
            var fmt = src.source_format ? src.source_format + ' feed' : '';
            return {
                type:          type,
                label:         src.name || (type + ' ' + src.id),
                description:   [src.provider, fmt].filter(Boolean).join(' · ')
                               || (type === 'feed' ? 'Feed' : 'Server'),
                source_id:     String(src.id),
                provider:      src.provider,
                url:           src.url,
                source_format: src.source_format,
                feed_events:   (src.event_uuids || []).length || undefined,
                // Not this event's. Provenance is binary (D2); the node's type
                // already says it is a feed and not another event.
                scope:         'foreign'
            };
        }

        /* ── node drawings (misp-pivot-nodes) ──────────────────── */
        // Each MISP element draws its S design at rest, its M chip once the
        // canvas renders it at the chip's size, and its richest drawing (the XL
        // card for events and objects, the chip otherwise) on hover or a lone
        // selection. XL stays out of the zoom tiers so every element shares one
        // footprint, and so one threshold. Badges stay the explorer's own.
        var CHIP = { width: 140, height: 44 };

        // Spaces the layout for the glyph at rest rather than the chip's half
        // width, so chips may touch once zoomed in.
        var LAYOUT_SIZE = 45;

        // The chip engages a little before zoom 1, drawn slightly under its size.
        var CHIP_FROM_ZOOM = 0.8;

        // The others fall back to their chip at XL, so once the chip tier is on
        // screen their hover drawing would only repeat it, smaller.
        var HAS_OWN_XL = { event: true, object: true };

        function withBadges(style) {
            return Object.assign({}, style, { badges: nodeBadges });
        }

        function elementOf(node) {
            var d = node.getData();
            if (!d) return undefined;
            if (d.image) return 'image';
            return d.type === 'tag' ? 'taxonomy' : d.type;
        }

        var ELEMENT_LABELS = { taxonomy: 'tag', cluster: 'galaxy cluster' };

        /* ── groups (pivotick UI.simplify) ─────────────────────── */
        // A group holds one kind: the element and MISP's own type, so an ip-src
        // never shares with an ip-dst, nor an attribute with an object of that name.
        // The element stays the renderer's type (nodeStyleMap, legend, facets).
        var GROUP_MIN_SIZE = 5;

        // typeLabel is handed the key alone, so the galaxy's name is kept as the
        // key is made.
        var _galaxyNames = {};

        // Each simplify rule's label by id, for a group drawn while the
        // graph is still being constructed and cannot be asked.
        var _ruleLabels = {};

        // A module's results never share a group with MISP's own records.
        var RESULT_KEY = '|module';

        function mispTypeOf(node) {
            var key = mispKindOf(node);
            return key && isEnrichmentResult(node) ? key + RESULT_KEY : key;
        }

        function baseKey(key) {
            return key && key.slice(-RESULT_KEY.length) === RESULT_KEY ? key.slice(0, -RESULT_KEY.length) : key;
        }

        function mispKindOf(node) {
            var e = elementOf(node);
            if (!e) return undefined;
            var d = node.getData();
            if (e === 'attribute' && d['attr-type']) return 'attribute:' + d['attr-type'];
            if (e === 'object' && d.name) return 'object:' + d.name;
            if (e === 'cluster' && d.galaxy_type) {
                if (d.galaxy_name) _galaxyNames[d.galaxy_type] = d.galaxy_name;
                return 'cluster:' + d.galaxy_type;
            }
            if (e === 'taxonomy' && d.name) return 'tag:' + String(d.name).split(':')[0];
            return e;
        }

        function groupTypeName(key) {
            key = baseKey(key);
            if (!key) return 'node';
            var i = key.indexOf(':');
            if (i < 0) return ELEMENT_LABELS[key] || key;
            var sub = key.slice(i + 1);
            return key.slice(0, i) === 'cluster' ? (_galaxyNames[sub] || sub) : sub;
        }

        function groupTypeLabel(key, count) {
            return count + ' × ' + groupTypeName(key);
        }

        // The element all of a group's members are, or null for a mixed group.
        function groupElement(info) {
            var seen = {};
            info.members.forEach(function (n) { seen[elementOf(n) || ''] = true; });
            var elements = Object.keys(seen);
            return elements.length === 1 && elements[0] ? elements[0] : null;
        }

        var KEY_ELEMENT = { tag: 'taxonomy' };

        // What misp-pivot-nodes' group chip draws, from the library's GroupInfo.
        function groupView(info) {
            var parts = Object.keys(info.typeCounts).map(function (key) {
                var head = baseKey(key).split(':')[0];
                return { key: key, entity: KEY_ELEMENT[head] || head,
                         name: groupTypeName(key), count: info.typeCounts[key] };
            }).sort(function (a, b) { return b.count - a.count; });
            return {
                entity: groupElement(info) || 'mixed',
                parts: parts,
                count: info.members.length,
                title: info.title,
                ruleLabel: _graph && _graph.simplify ? _graph.simplify.ruleLabel(info.rule)
                                                     : (_ruleLabels[info.rule] || info.rule),
                via: info.landing ? info.landing.pivotLabel : undefined
            };
        }

        // At rest the library's ring and count; zoomed in, the deck chip. A group
        // is spaced like an element, so its chip clears its neighbours' and
        // engages at the same zoom.
        function groupStyle(info, base) {
            var view = groupView(info);
            var marks = groupEnrichmentBadges(info);
            return {
                layoutSize: LAYOUT_SIZE,
                badges: marks.length ? function () { return groupEnrichmentBadges(info); } : undefined,
                tiers: ((base && base.tiers) || []).concat(hasChips ? [{
                    width: CHIP.width, height: CHIP.height,
                    minRenderedSize: 2 * LAYOUT_SIZE * CHIP_FROM_ZOOM,
                    style: {
                        shape: 'none', color: 'transparent', strokeWidth: 0, text: '',
                        html: function () { return window.MispPivotNodes.groupCard(view); }
                    }
                }] : [])
            };
        }

        // The open group's chip reads as the closed one's label.
        function groupOutline(info) {
            return _graph && _graph.simplify ? _graph.simplify.labelOf(info) : undefined;
        }

        var GROUP_PEEK = 3;

        function groupTooltipExtra(info) {
            var wrap = document.createElement('div');
            info.members.slice(0, GROUP_PEEK).forEach(function (n) {
                var row = document.createElement('div');
                row.style.cssText = 'font-family:var(--bs-font-monospace,monospace);font-size:.8em;' +
                    'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:260px;';
                row.textContent = (n.getData() || {}).label || n.id;
                wrap.appendChild(row);
            });
            var rest = info.members.length - GROUP_PEEK;
            if (rest > 0) {
                var more = document.createElement('div');
                more.style.cssText = 'font-size:.75em;opacity:.65;';
                more.textContent = '+' + rest + ' more';
                wrap.appendChild(more);
            }
            return wrap;
        }

        function simplifyOption() {
            return {
                rules: [
                    { kind: 'landings',   enabled: true,  minSize: GROUP_MIN_SIZE },
                    { kind: 'neighbours', enabled: false, minSize: GROUP_MIN_SIZE },
                    { kind: 'chains',     enabled: false, minSize: GROUP_MIN_SIZE },
                    { kind: 'degree',      enabled: false },
                    { kind: 'kcore',       enabled: false },
                    { kind: 'communities', enabled: false }
                ],
                typeOf: mispTypeOf,
                // A drawn node leaves its own colour transparent.
                colorOf: function (node) { return elementColour(elementOf(node)); },
                typeLabel: groupTypeLabel
            };
        }

        // The legend samples a node's resolved `color`, which a drawn node leaves
        // transparent, so the Element rows carry the entity hues themselves.
        function elementColour(e) {
            var P = window.MispPivotNodes.palette();
            return {
                event: P.event.core, object: P.object.core, attribute: P.attribute.core,
                image: P.attribute.core, cluster: P.galaxy.core, taxonomy: P.tag.core,
                feed: P.feed.core, server: P.server.core
            }[e];
        }

        function elementLegendEntries(graph) {
            var seen = {};
            graph.getMutableNodes().forEach(function (n) {
                var e = elementOf(n);
                if (e) seen[e] = true;
            });
            return Object.keys(seen).map(function (e) {
                return {
                    id: e, label: ELEMENT_LABELS[e] || e, color: elementColour(e) || '#888',
                    predicate: function (node) { return elementOf(node) === e; }
                };
            });
        }

        // The tags and clusters this event carries, on itself or on one of its
        // live attributes, by node id: { tag } or { cluster }.
        var _ownLabels = null, _ownLabelsFor = null;
        function ownLabels() {
            if (_ownLabels && _ownLabelsFor === _event) return _ownLabels;
            var byUuid = ownAttributeIndex().byUuid;
            var recs = [(_event && _event.Event) || {}].concat(Object.keys(byUuid).map(function (uuid) {
                return byUuid[uuid];
            }));
            _ownLabels = labelsOf(recs.map(tagFields));
            _ownLabelsFor = _event;
            return _ownLabels;
        }

        function inThisEvent(node) {
            var d = node.getData() || {};
            if (d.scope === 'self') return true;
            return (d.type === 'tag' || d.type === 'cluster') && !!ownLabels()[node.id];
        }

        function provenanceOf(node) {
            if (isEnrichmentResult(node)) return 'module';
            return inThisEvent(node) ? 'self' : 'elsewhere';
        }

        function enrichmentLegendEntry() {
            var ink = ENRICH_INK[mispTheme()];
            return { id: 'module', label: 'From enrichment', color: ink,
                     badge: { color: ink, svgIcon: ENRICH_MARK },
                     predicate: isEnrichmentResult };
        }

        function provenanceLegendEntries(graph) {
            var seen = {};
            graph.getMutableNodes().forEach(function (n) { seen[provenanceOf(n)] = true; });
            return [
                { id: 'self', label: 'This event', color: window.MispPivotNodes.palette().event.core,
                  predicate: function (node) { return provenanceOf(node) === 'self'; } },
                { id: 'elsewhere', label: 'Elsewhere', color: '#888',
                  predicate: function (node) { return provenanceOf(node) === 'elsewhere'; } },
                enrichmentLegendEntry()
            ].filter(function (e) { return seen[e.id]; });
        }

        // A host with no "this event" still separates what a module said (E5).
        // Keyed on `module`, which only results carry, the section is not drawn
        // until one lands.
        function enrichmentLegendEntries(graph) {
            if (!graph.getMutableNodes().some(isEnrichmentResult)) return [];
            return [
                { id: 'misp', label: 'In MISP', color: ENRICH_PLAIN[mispTheme()],
                  predicate: function (node) { return !isEnrichmentResult(node); } },
                enrichmentLegendEntry()
            ];
        }

        function provenanceSection() {
            if (hasProvenance) return { id: 'provenance', title: 'Provenance', entries: provenanceLegendEntries };
            if (canEnrich) return { id: 'provenance', title: 'Provenance', key: 'module', entries: enrichmentLegendEntries };
            return null;
        }

        // Labels are not drawn by default — render data.label above each node.
        function labelStyle() {
            return {
                text: function (node) {
                    var d = node.getData();
                    return d ? d.label : '';
                },
                textVerticalShift: -1
            };
        }

        // What misp-pivot-nodes does not draw.
        function otherNodeStyles() {
            return {
                // Image attachments (screenshots) draw an embedded thumbnail.
                image:     {
                    imageFit:    'frame',
                    size:        80,
                    strokeColor: 'rgba(255,255,255,0.55)',
                    strokeWidth: 2,
                    imagePath:   function (node) {
                        var d = node.getData();
                        return d ? d.imageUrl : undefined;
                    }
                }
            };
        }

        // The neighbours panel's graph: each element as its small node, with no
        // card tiers, no focus card and no pivot badges, and never zoomed past 1×.
        function neighborsGraph() {
            var small = window.MispPivotNodes.options({ size: 'S', theme: mispTheme() }).nodeStyleMap;
            return {
                render: {
                    nodeStyleMap: Object.assign(small, otherNodeStyles()),
                    defaultNodeStyle: labelStyle(),
                    maxZoom: 1
                }
            };
        }

        function mispNodeStyles() {
            var N = window.MispPivotNodes;
            var rest = N.options({
                size:    'S',
                theme:   mispTheme(),
                fontUrl: baseurl + '/webfonts/misp-iconify.woff2'
            }).nodeStyleMap;
            var chip = N.styleMap('M'), focus = N.styleMap('XL');
            var map = {};
            Object.keys(rest).forEach(function (entity) {
                map[entity] = Object.assign(withBadges(rest[entity]), {
                    tiers:      hasChips ? [{ width: CHIP.width, height: CHIP.height,
                                              minRenderedSize: 2 * LAYOUT_SIZE * CHIP_FROM_ZOOM,
                                              style: withBadges(chip[entity]) }] : undefined,
                    focusTier:  withBadges(focus[entity]),
                    layoutSize: LAYOUT_SIZE
                });
                if (!HAS_OWN_XL[entity]) map[entity].focusTierYieldsAt = 0;
            });
            return map;
        }

        /* ── build pivotick nodes/edges from a MISP event ──────── */
        function buildGraphData(event) {
            var ev      = (event && event.Event) ? event.Event : {};
            var nodes   = [];
            var edges   = [];
            var nodeSet = {};   // id -> true (dedupe + existence check for edges)
            var edgeSet = {};   // key -> true (dedupe)

            function addNode(id, node) {
                if (nodeSet[id]) return;
                nodeSet[id] = true;
                nodes.push(node);
            }
            // `kind` is how the edge came to exist (D1's first edge dimension) and is
            // part of the edge's identity: two kinds may assert the same label between
            // the same pair, and both belong on the canvas.
            function addEdge(from, to, label, kind, extra) {
                if (!nodeSet[from] || !nodeSet[to]) return false;   // only link existing nodes
                var key = kind + ' ' + from + ' ' + to + ' ' + (label || '');
                if (edgeSet[key]) return false;
                edgeSet[key] = true;
                var data = Object.assign({ kind: kind, label: label || '' }, extra);
                edges.push({ from: from, to: to, data: data });
                return true;
            }

            // Register an attribute's id (dedupe + edge existence) and return its node
            // dict, or null if already added. The caller decides where to place it —
            // top-level (nodes) or nested inside an object (children).
            function buildAttributeNode(attr, obj) {
                var id = 'attr:' + attr.uuid;
                if (nodeSet[id]) return null;
                nodeSet[id] = true;
                var owner = ownerIn(ev, attr);
                return { id: id, data: obj ? objectChildData(obj, attr, owner) : attributeNodeData(attr, owner) };
            }

            // Top-level attribute node (used for event-level attributes).
            function addAttributeNode(attr) {
                var node = buildAttributeNode(attr);
                if (node) nodes.push(node);
                return 'attr:' + attr.uuid;
            }

            /* The seed decides what each resolution level contributes (D12); this
               builder only lays it out. */
            var seed                = computeSeed(ev);
            var linkedAttrUuids     = seed.linkedAttrUuids;
            var connectedObjUuids   = seed.connectedObjUuids;

            /* L1 events — this one or another, each an analyst relationship's
               endpoint. Another event is a leaf: only its record came along. */
            seed.eventNodes.forEach(function (e) {
                addNode(e.id, { id: e.id, data: eventNodeData(e.record) });
            });

            /* L1 — another event's attribute or object at the far end of an
               analyst relationship, beside its event's card. It lands free and
               closed: the payload names the element, not the rest of its object. */
            seed.foreignNodes.forEach(function (f) {
                var owner  = provenance(f.event.id, f.event.uuid);
                var cardId = 'event:' + f.event.uuid;
                addNode(f.id, { id: f.id, data: f.type === 'attribute'
                    ? attributeNodeData(f.record, owner) : objectNodeData(f.record, owner) });
                addNode(cardId, { id: cardId, data: eventNodeData(f.event) });
                addEdge(f.id, cardId, '', 'in-event');
            });

            /* L1 — a galaxy cluster an analyst relationship points at, as the
               node the tags pivot would draw for it. */
            seed.clusterNodes.forEach(function (c) {
                addNode(c.id, clusterNode(c.id, c.record));
            });

            /* Event-level attributes, surfaced only when an authored relationship
               touches them (L1) or, when L2 fits, a feed or server hits them, so
               every node stays connected to the graph. This also covers
               referenced screenshots. */
            (ev.Attribute || []).forEach(function (attr) {
                if (!isDeleted(attr) && (linkedAttrUuids[attr.uuid] || seed.l2AttrUuids[attr.uuid])) {
                    addAttributeNode(attr);
                }
            });

            /* L1 objects (an authored relationship touches them) and, when the
               level fits the budget, L2's: objects linked only by a feed or
               server hit, whose edge is drawn below. */
            (ev.Object || []).forEach(function (obj) {
                if (isDeleted(obj)) return;
                // Everything else is offered by the element pivot instead.
                if (!connectedObjUuids[obj.uuid] && !seed.l2Uuids[obj.uuid]) return;
                var objId = 'obj:' + obj.uuid;

                // Attributes are nested as children of their object rather than
                // linked by an edge — containment expresses the object/attribute
                // relationship (the object_relation is folded into the description).
                var children = [];
                (obj.Attribute || []).forEach(function (attr) {
                    if (isDeleted(attr)) return;
                    var child = buildAttributeNode(attr, obj);
                    if (child) children.push(child);
                });

                addNode(objId, {
                    id:       objId,
                    children: children,
                    data:     objectNodeData(obj, ownerIn(ev, obj))
                });
            });

            /* Object references (added last so both ends already exist) */
            (ev.Object || []).forEach(function (obj) {
                var objId = 'obj:' + obj.uuid;
                (obj.ObjectReference || []).forEach(function (ref) {
                    if (isDeleted(ref)) return;
                    var prefix   = (String(ref.referenced_type) === '1') ? 'obj:' : 'attr:';
                    var targetId = prefix + ref.referenced_uuid;
                    var rel      = ref.relationship_type || 'related-to';
                    addEdge(objId, targetId, rel, 'object-reference',
                            { uuid: ref.uuid, relationship_type: rel });
                });
            });

            /* Analyst relationships (D1's second kind, L1 alongside object
               references). Both endpoints were seeded above, so a rejected edge
               means the target has no node on this canvas at all — a relationship
               pointing at another event's attribute, or at an element type the
               canvas does not draw. */
            eachAnalystRelationship(ev, function (rel, fromId, toId) {
                if (!fromId || !toId || fromId === toId) return;
                var type = rel.relationship_type || 'related-to';
                addEdge(fromId, toId, type, 'analyst-relationship',
                        { authors: rel.authors, orgc: rel.orgc_uuid, uuid: rel.uuid,
                          relationship_type: type });
            });

            /* Feed and server correlations (D1). A hit is drawn from elements the
               seed already took — L2 took an object for its hits — and a source
               node appears with its first drawable hit. Past 10,000
               hits MISP drops the sources and flags each attribute FeedHit, which
               attributeNodeData turns into a badge.
               The edge runs source → attribute: pivotick stands in for an edge
               into a collapsed object's child, and draws none out of one. */
            function eachLiveAttribute(fn) {
                (ev.Attribute || []).forEach(function (a) { if (!isDeleted(a)) fn(a); });
                (ev.Object || []).forEach(function (o) {
                    if (isDeleted(o)) return;
                    (o.Attribute || []).forEach(function (a) { if (!isDeleted(a)) fn(a); });
                });
            }
            SOURCES.forEach(function (s) {
                var known = sourceMap(ev, s.scope);
                eachLiveAttribute(function (a) {
                    (a[s.scope] || []).forEach(function (hit) {
                        var attrId = 'attr:' + a.uuid;
                        if (!nodeSet[attrId]) return;
                        var srcId = s.type + ':' + hit.id;
                        if (!nodeSet[srcId]) {
                            addNode(srcId, { id: srcId,
                                data: sourceNodeData(s.type, known[String(hit.id)] || hit) });
                        }
                        addEdge(srcId, attrId, '', s.kind);
                    });
                });
            });

            return { nodes: nodes, edges: edges };
        }

        /* ── analyst data: one folded badge, detail in the sidebar (D2, §6.2) ── */
        // Notes and opinions on an element, and the notes and opinions left on
        // those in turn — everything somebody has said about it. Relationships are
        // not counted: they are drawn as edges.
        function analystRecords(rec) {
            var out = [];
            (function walk(r, depth) {
                ['Note', 'Opinion'].forEach(function (k) {
                    (r[k] || []).forEach(function (a) {
                        out.push({ kind: k, rec: a, depth: depth });
                        walk(a, depth + 1);
                    });
                });
            })(rec, 0);
            return out;
        }

        // The existing bands (opinion_scale.ctp): under 41 disagree, over 60
        // agree. Only opinions on the element itself decide its colour; an
        // opinion on a note is about the note.
        function opinionMood(values) {
            if (!values.length) return 'none';
            var mean = values.reduce(function (s, v) { return s + v; }, 0) / values.length;
            return mean < 41 ? 'disputed' : (mean > 60 ? 'endorsed' : 'neutral');
        }

        // The two fields a node carries, flat so the filter builder can index
        // them. Absent — not zero — without analyst data, so such a node's data
        // is exactly what it was before.
        function analystFields(rec) {
            var all = analystRecords(rec);
            if (!all.length) return {};
            var own = (rec.Opinion || []).map(function (o) { return Number(o.opinion); })
                .filter(function (v) { return !isNaN(v); });
            return { analyst_count: all.length, analyst_mood: opinionMood(own) };
        }

        var MOOD_COLOR = { disputed: '#b94a48', endorsed: '#6fbe80', neutral: '#999', none: '#999' };

        var FEED_COLOR = '#5bc0de';

        function nodeBadges(node) {
            return analystBadges(node).concat(feedHitBadges(node), tagBadges(node), enrichmentBadges(node), originBadges(node));
        }

        function originBadges(node) {
            var d = node && node.getData ? node.getData() : null;
            var origin = d && host.origins && d.scope === 'foreign' ? host.origins[d.event_id] : null;
            if (!origin) return [];
            return [{
                position:  'ne',
                iconClass: origin.role === 'extended' ? 'fas fa-code-merge' : 'fas fa-code-branch',
                color:     origin.color,
                title:     origin.title
            }];
        }

        function clusterName(c) {
            return (c.galaxy_name || c.galaxy_type || 'Cluster') + ': ' + c.value;
        }

        // An attribute's tags and clusters, read in the sidebar. An event card
        // draws its own.
        function tagBadges(node) {
            var d = node && node.getData ? node.getData() : null;
            if (!d || d.type !== 'attribute' || !(d.tags || d.clusters)) return [];
            var tags = d.tags || [], clusters = d.clusters || [];
            var P = window.MispPivotNodes && window.MispPivotNodes.palette();
            return [{
                position:  'se',
                iconClass: 'fas fa-tag',
                color:     tags.length ? (tags[0].colour || '#888') : ((P && P.galaxy.core) || '#888'),
                title:     tags.map(function (t) { return t.name; }).concat(clusters.map(clusterName)).join('\n'),
                onClick:   function (e, clicked) { showInSidebar(clicked); }
            }];
        }

        // The degraded shape: MISP saw the value in a feed but did not say which,
        // so there is no node to draw an edge to (§7).
        function feedHitBadges(node) {
            var d = node && node.getData ? node.getData() : null;
            if (!d || !d.feed_hit) return [];
            return [{
                position:  'sw',
                iconClass: 'fas fa-rss',
                color:     FEED_COLOR,
                title:     'Seen in a feed — too many hits in this event to name which'
            }];
        }

        // An icon, not a number: counts on the rim are the pivots'.
        function analystBadges(node) {
            var d = node && node.getData ? node.getData() : null;
            if (!d || !d.analyst_count) return [];
            var n = d.analyst_count;
            return [{
                position:  'nw',
                iconClass: n === 1 ? 'fas fa-comment' : 'fas fa-comments',
                color:     MOOD_COLOR[d.analyst_mood] || MOOD_COLOR.none,
                title:     n + (n === 1 ? ' note or opinion' : ' notes and opinions')
                           + (d.analyst_mood !== 'none' ? ' — ' + d.analyst_mood : ''),
                onClick:   function (e, clicked) { showInSidebar(clicked); }
            }];
        }

        // Every record of this event that can carry analyst data, by uuid.
        var _analystIndex = null, _analystIndexFor = null;
        function analystSource(uuid) {
            if (!_analystIndex || _analystIndexFor !== _event) {
                var ev = (_event && _event.Event) || {};
                _analystIndex = {};
                if (ev.uuid) _analystIndex[ev.uuid] = ev;
                (ev.Attribute || []).forEach(function (a) { _analystIndex[a.uuid] = a; });
                (ev.Object || []).forEach(function (o) {
                    _analystIndex[o.uuid] = o;
                    (o.Attribute || []).forEach(function (a) { _analystIndex[a.uuid] = a; });
                });
                _analystIndexFor = _event;
            }
            return _analystIndex[uuid] || null;
        }

        function eventHasAnalystData(ev) {
            if (analystRecords(ev).length) return true;
            return (ev.Attribute || []).some(function (a) { return analystRecords(a).length; })
                || (ev.Object || []).some(function (o) {
                    return analystRecords(o).length
                        || (o.Attribute || []).some(function (a) { return analystRecords(a).length; });
                });
        }

        function showInSidebar(node) {
            if (!_graph || !node) return;
            if (typeof _graph.selectElement === 'function') _graph.selectElement(node);
            var sidebar = _graph.UIManager && _graph.UIManager.sidebar;
            if (sidebar && typeof sidebar.showSidebar === 'function') sidebar.showSidebar();
        }

        function el(tag, cls, textContent) {
            var e = document.createElement(tag);
            if (cls) e.className = cls;
            if (textContent != null) e.textContent = textContent;
            return e;
        }

        function renderAnalystPanel(selection) {
            var wrap = el('div', 'pe-analyst');
            wrap.style.cssText = 'font-size:.85rem;';
            if (Array.isArray(selection)) {
                wrap.appendChild(el('div', null, 'Select a single element to read what was said about it.'));
                return wrap;
            }
            var d = selection && selection.getData ? selection.getData() : null;
            var rec = d && d.uuid && analystSource(d.uuid);
            var a = rec ? window.MispPivotSidebar.analyst(rec) : null;
            if (!a || !a.items.length) {
                var none = el('div', null, 'No notes or opinions on this element.');
                none.style.cssText = 'opacity:.65;';
                wrap.appendChild(none);
                return wrap;
            }
            wrap.appendChild(window.MispPivotSidebarView.analystThread(a));
            return wrap;
        }

        function analystPanelTitle(selection) {
            var d = (selection && !Array.isArray(selection) && selection.getData) ? selection.getData() : null;
            return 'Notes & opinions' + (d && d.analyst_count ? ' (' + d.analyst_count + ')' : '');
        }

        function analystPanel() {
            return { id: 'analyst-data', title: analystPanelTitle, render: renderAnalystPanel };
        }

        /* ── pivot: correlations (R1) ──────────────────────────── */
        // Counts come from /events/correlationCounts, which counts exactly the
        // pairs /events/correlatedAttributes returns — so a summary never promises
        // what the fetch cannot bring. Null until loaded: nothing applies until then.
        // Another event's elements are counted as they land, into _foreignCounts.
        var _counts = null;
        var _foreignCounts = { attributes: {}, objects: {} };

        function attributeCount(uuid) {
            return (_counts && _counts.attributes[uuid]) || _foreignCounts.attributes[uuid] || 0;
        }

        function correlationCount(d) {
            if (!_counts || !d || !d.uuid) return 0;
            if (d.type === 'attribute') return attributeCount(d.uuid);
            if (d.type === 'object')    return _counts.objects[d.uuid] || _foreignCounts.objects[d.uuid] || 0;
            return 0;
        }

        function sumCounts(nodes, count) {
            return nodes.reduce(function (s, n) { return s + count(n.getData()); }, 0);
        }

        // This event's live attributes by uuid, and each object's attribute uuids.
        // Built once per payload: isValidConnection reads it on every pointer move.
        var _ownIndex = null, _ownIndexFor = null;
        function ownAttributeIndex() {
            if (_ownIndex && _ownIndexFor === _event) return _ownIndex;
            var ev = (_event && _event.Event) || {};
            var byUuid = {}, byObject = {};
            (ev.Attribute || []).forEach(function (a) { if (!isDeleted(a)) byUuid[a.uuid] = a; });
            (ev.Object || []).forEach(function (o) {
                if (isDeleted(o)) return;
                byObject[o.uuid] = [];
                (o.Attribute || []).forEach(function (a) {
                    if (isDeleted(a)) return;
                    byUuid[a.uuid] = a;
                    byObject[o.uuid].push(a.uuid);
                });
            });
            _ownIndexFor = _event;
            _ownIndex = { byUuid: byUuid, byObject: byObject };
            return _ownIndex;
        }

        // Is this node one of this event's live attributes or objects? A
        // correlated attribute a pivot brought in looks the same but is not.
        function isOwnElement(d) {
            if (!d || !d.uuid) return false;
            var index = ownAttributeIndex();
            if (d.type === 'object')    return !!index.byObject[d.uuid];
            if (d.type === 'attribute') return !!index.byUuid[d.uuid];
            return false;
        }

        // The attributes a selection stands for, as { uuid, name, value }: this
        // event's from its payload, another event's object through its children.
        function attributesOf(nodes) {
            var index = ownAttributeIndex();
            var out = [];
            function own(uuid) {
                var a = index.byUuid[uuid] || {};
                return { uuid: uuid, name: a.object_relation || a.type, value: a.value };
            }
            function drawn(d) {
                return { uuid: d.uuid, name: d.object_relation || d['attr-type'], value: d.value };
            }
            nodes.forEach(function (n) {
                var d = n.getData() || {};
                if (d.type === 'attribute') {
                    out.push(index.byUuid[d.uuid] ? own(d.uuid) : drawn(d));
                } else if (d.type === 'object') {
                    if (index.byObject[d.uuid]) out = out.concat(index.byObject[d.uuid].map(own));
                    else (n.children || []).forEach(function (c) {
                        var cd = c.getData ? c.getData() : c.data;
                        if (cd && cd.type === 'attribute' && cd.uuid) out.push(drawn(cd));
                    });
                }
            });
            return out;
        }

        function attributeUuidsOf(nodes) {
            return attributesOf(nodes).map(function (a) { return a.uuid; });
        }

        // Nodes and edges a pivot lands, each id once.
        function landing() {
            var nodes = [], edges = [], seen = {};
            return {
                node: function (n) {
                    if (!seen[n.id]) { seen[n.id] = n; nodes.push(n); }
                    return n.id;
                },
                get: function (id) { return seen[id] || null; },
                edge: function (e) {
                    if (!seen[e.id]) { seen[e.id] = true; edges.push(e); }
                },
                result: function () { return { nodes: nodes, edges: edges }; }
            };
        }

        function mergePriorities(ranks) {
            Object.keys(ranks || {}).forEach(function (k) {
                if (!uiPriorities[k]) uiPriorities[k] = ranks[k];
            });
        }

        // Another event's card, drawn for context: it holds nothing.
        function eventCardNode(card) {
            return { id: 'event:' + card.uuid, data: eventNodeData(card) };
        }

        // Another event's object, closed, with its live attributes.
        function foreignObjectNode(obj, owner) {
            return {
                id:       'obj:' + obj.uuid,
                data:     objectNodeData(obj, owner),
                children: (obj.Attribute || []).filter(function (a) { return !isDeleted(a); })
                    .map(function (a) { return { id: 'attr:' + a.uuid, data: objectChildData(obj, a, owner) }; })
            };
        }

        function inEventEdge(fromId, cardId) {
            return { id: 'in-event:' + fromId, from: fromId, to: cardId, data: { kind: 'in-event', label: '' } };
        }

        // A correlated attribute lands inside its object, when the user may read
        // it, or free; its event's card sits beside it, joined by an in-event
        // edge. This event's side of each pair is brought along when it is not
        // drawn yet (an event-level attribute, or one inside an object L2 skipped).
        function correlationResult(payload) {
            var index = ownAttributeIndex();
            var cards = payload.events || {}, objects = payload.objects || {};
            var land = landing(), sourceNodes = [], seen = {};
            mergePriorities(payload.ui_priorities);
            (payload.pairs || []).forEach(function (p) {
                var ev    = p.Event || {};
                var owner = provenance(ev.id, ev.uuid);
                var cid   = land.node(eventCardNode(cards[ev.id] || ev));
                var obj   = p.Object && objects[p.Object.uuid];
                var tid   = 'attr:' + p.Attribute.uuid;
                var holder = obj
                    ? land.node(foreignObjectNode(obj, owner))
                    : land.node({ id: tid, data: attributeNodeData(p.Attribute, owner) });
                // Inside its object whatever the object's payload says.
                var box = obj && land.get(holder);
                if (box && !box.children.some(function (c) { return c.id === tid; })) {
                    box.children.push({ id: tid, data: objectChildData(obj, p.Attribute, owner) });
                }
                land.edge(inEventEdge(holder, cid));
                var sid = 'attr:' + p.source_uuid;
                if (!seen[sid]) {
                    seen[sid] = true;
                    var drawn = _graph && typeof _graph.getMutableNode === 'function' && _graph.getMutableNode(sid);
                    var own = index.byUuid[p.source_uuid];
                    if (!drawn && own) {
                        sourceNodes.push({ id: sid, data: attributeNodeData(own, ownerIn(_event.Event, own)) });
                    }
                }
                // Between two drawn events a pair can come back the other way.
                var back = 'corr:' + p.Attribute.uuid + ':' + p.source_uuid;
                if (land.get(back) || (_graph && typeof _graph.getMutableEdge === 'function' && _graph.getMutableEdge(back))) return;
                land.edge({ id: 'corr:' + p.source_uuid + ':' + p.Attribute.uuid, from: sid, to: tid,
                            data: { kind: 'correlation', label: '' } });
            });
            sourceNodes.forEach(land.node);
            return land.result();
        }

        function postJson(path, body, signal) {
            return fetch(baseurl + path, {
                method: 'POST',
                credentials: 'same-origin',
                signal: signal,
                headers: {
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': (window.csrfToken || '')
                },
                body: JSON.stringify(body)
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
        }

        function fetchCorrelated(body, signal) {
            return postJson('/events/correlatedAttributes/' + encodeURIComponent(eventId) + '.json', body, signal)
                .then(correlationResult);
        }

        var CORRELATION_PIVOT = 'correlations';

        // The selection's attributes that correlate, each with its own count: an
        // object is closed, so this is where one of its attributes is picked.
        function correlatingAttributes(nodes) {
            return attributesOf(nodes).map(function (a) {
                return { uuid: a.uuid, label: (a.name || 'attribute') + ': ' + String(a.value == null ? '' : a.value).slice(0, 60),
                         count: attributeCount(a.uuid) };
            }).filter(function (a) { return a.count > 0; });
        }

        function pickedAttributes(nodes, narrowing) {
            var all = correlatingAttributes(nodes);
            var picks = (narrowing && narrowing.attribute) || [];
            return picks.length ? all.filter(function (a) { return picks.indexOf(a.uuid) !== -1; }) : all;
        }

        function correlationPivot() {
            return {
                id:            CORRELATION_PIVOT,
                label:         'Correlations',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) { return correlationCount(n.getData()) > 0; });
                },
                summarize: function (nodes, narrowing) {
                    var all = correlatingAttributes(nodes);
                    var summary = { total: pickedAttributes(nodes, narrowing).reduce(function (s, a) { return s + a.count; }, 0) };
                    if (all.length > 1) {
                        summary.facets = [{ key: 'attribute', label: 'Attribute', type: 'multiselect',
                            options: all.map(function (a) { return { label: a.label, value: a.uuid, count: a.count }; }) }];
                    }
                    return summary;
                },
                fetch: function (nodes, narrowing, ctx) {
                    var picks = (narrowing && narrowing.attribute) || [];
                    var uuids = attributeUuidsOf(nodes).filter(function (uuid) {
                        return !picks.length || picks.indexOf(uuid) !== -1;
                    });
                    return fetchCorrelated({ attribute_uuids: uuids }, ctx && ctx.signal);
                }
            };
        }

        // Declared, never queried (pivotick draws the rim badge from it): a counted
        // attribute or object wears the number of correlations the pivot would
        // bring. Zero declares nothing.
        function declarePotential(node) {
            var n = correlationCount(node.getData());
            if (n) node.setPotential('correlations', n);
        }

        // Another event's attribute or object, counted once: the nodes that land
        // together are asked for in one request, and declare when it answers.
        var _foreignAsked = {}, _foreignQueue = [], _foreignTimer = null;
        // Asked and answered, so a uuid the answer leaves out has no correlations.
        var _foreignAnswered = {};
        function queueForeignCounts(graph, node) {
            var d = node.getData() || {};
            if ((d.type !== 'attribute' && d.type !== 'object') || !d.uuid || isOwnElement(d)) return;
            var uuids = attributeUuidsOf([node]).filter(function (uuid) {
                if (_foreignAsked[uuid]) return false;
                return (_foreignAsked[uuid] = true);
            });
            if (!uuids.length) return;
            _foreignQueue.push({ node: node, uuids: uuids });
            if (!_foreignTimer) _foreignTimer = setTimeout(function () { loadForeignCounts(graph); }, 0);
        }

        function loadForeignCounts(graph) {
            var batch = _foreignQueue;
            _foreignQueue = [];
            _foreignTimer = null;
            var uuids = batch.reduce(function (all, b) { return all.concat(b.uuids); }, []);
            return postJson('/events/correlationCounts/' + encodeURIComponent(eventId) + '.json', { attribute_uuids: uuids })
                .then(function (c) {
                    Object.assign(_foreignCounts.attributes, (c && c.attributes) || {});
                    Object.assign(_foreignCounts.objects, (c && c.objects) || {});
                    uuids.forEach(function (uuid) { _foreignAnswered[uuid] = true; });
                    batch.forEach(function (b) { declarePotential(b.node); });
                    graph.renderer.update();
                    refreshSidebar();
                })
                .catch(function (err) {
                    uuids.forEach(function (uuid) { delete _foreignAsked[uuid]; });
                    console.error('[pivot-explorer] correlation counts failed:', err);
                });
        }

        // Once on the counts, then on each node as it lands (an ingest, an undo's
        // redo), before the render that follows it.
        function declareAllPotential(graph) {
            function declare(node) {
                declarePotential(node);
                queueForeignCounts(graph, node);
            }
            graph.getMutableNodes().forEach(declare);
            graph.renderer.update();
            graph.on('nodeAdd', declare);
        }

        function loadCorrelationCounts(graph) {
            return fetch(baseurl + '/events/correlationCounts/' + encodeURIComponent(eventId) + '.json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (c) {
                if (!c || !c.attributes) return;
                _counts = c;
                declareAllPotential(graph);
                refreshSidebar();
            })
            .catch(function (err) {
                console.error('[pivot-explorer] correlation counts failed:', err);
            });
        }

        /* ── pivot: the events of a MISP feed ──────────────────── */
        // The payload names, per attribute, the MISP-feed events its value is in;
        // /feeds/manifestEvents adds what each card draws, from the manifest the
        // instance holds. A feed event is a leaf, like another event, keyed by its
        // feed: two feeds can carry the same event.
        var FEED_EVENTS_PIVOT = 'feed-events';

        var _feeds = null, _feedsFor = null;
        function feedSource(feedId) {
            if (!_feeds || _feedsFor !== _event) {
                _feeds = sourceMap((_event && _event.Event) || {}, 'Feed');
                _feedsFor = _event;
            }
            return _feeds[String(feedId)] || null;
        }

        function feedEventUuids(d) {
            if (!d || d.type !== 'feed') return [];
            var src = feedSource(d.source_id);
            return (src && src.event_uuids) || [];
        }

        function feedEventId(feedId, uuid) {
            return 'feed-event:' + feedId + ':' + uuid;
        }

        function feedEventNodeData(card, src) {
            var orgc = card.Orgc && card.Orgc.name ? card.Orgc : null;
            var info = card.info != null ? String(card.info) : '';
            return {
                type:        'event',
                label:       info || card.uuid,
                description: [card.date, orgc && orgc.name, 'in ' + src.name].filter(Boolean).join(' · '),
                info:        info || undefined,
                date:        card.date || undefined,
                org:         orgc ? orgc.name : undefined,
                orgc:        orgc ? { name: orgc.name, uuid: orgc.uuid } : undefined,
                uuid:        card.uuid,
                context:     eventContext(card),
                tags:        tagFields(card).tags,
                clusters:    tagFields(card).clusters,
                // Read by the event drawings: a cached hit, and whose.
                _provenance: 'feed',
                source:      { kind: 'feed', type: 'Feed', name: src.name,
                               provider: src.provider, url: src.url },
                feed_id:     String(src.id),
                feed_name:   src.name,
                scope:       'foreign'
            };
        }

        // Each feed event lands beside its feed, joined to the attributes of this
        // event whose values it holds; one not drawn yet comes along.
        function feedEventsResult(feedNodes, payload) {
            var cards = (payload && payload.events) || {};
            var index = ownAttributeIndex();
            var nodes = [], edges = [], seen = {};
            function drawn(id) {
                return !!(_graph && typeof _graph.getMutableNode === 'function' && _graph.getMutableNode(id));
            }
            feedNodes.forEach(function (fn) {
                var d = fn.getData();
                var src = feedSource(d.source_id);
                if (!src) return;
                var feedCards = cards[String(src.id)] || {};
                var srcId = 'feed:' + src.id;
                (src.event_uuids || []).forEach(function (uuid) {
                    var id = feedEventId(src.id, uuid);
                    if (seen[id]) return;
                    seen[id] = true;
                    nodes.push({ id: id, data: feedEventNodeData(feedCards[uuid] || { uuid: uuid }, src) });
                    edges.push({ id: 'feedev:' + src.id + ':' + uuid, from: srcId, to: id,
                                 data: { kind: 'feed-event', label: '' } });
                });
                Object.keys(index.byUuid).forEach(function (attrUuid) {
                    var a = index.byUuid[attrUuid];
                    (a.Feed || []).forEach(function (hit) {
                        if (String(hit.id) !== String(src.id)) return;
                        var attrId = 'attr:' + attrUuid;
                        (hit.event_uuids || []).forEach(function (uuid) {
                            if (!seen[feedEventId(src.id, uuid)]) return;
                            if (!seen[attrId] && !drawn(attrId)) {
                                nodes.push({ id: attrId, data: attributeNodeData(a, ownerIn(_event.Event, a)) });
                            }
                            seen[attrId] = true;
                            edges.push({ id: 'feedhit:' + src.id + ':' + uuid + ':' + attrUuid,
                                         from: feedEventId(src.id, uuid), to: attrId,
                                         data: { kind: 'feed-correlation', label: '' } });
                        });
                    });
                });
            });
            return { nodes: nodes, edges: edges };
        }

        function fetchFeedEvents(feedNodes, signal) {
            var feeds = {};
            feedNodes.forEach(function (n) {
                var d = n.getData();
                feeds[d.source_id] = feedEventUuids(d);
            });
            return fetch(baseurl + '/feeds/manifestEvents.json', {
                method: 'POST',
                credentials: 'same-origin',
                signal: signal,
                headers: {
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': (window.csrfToken || '')
                },
                body: JSON.stringify({ feeds: feeds })
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (payload) { return feedEventsResult(feedNodes, payload); });
        }

        function feedEventsPivot() {
            return {
                id:            FEED_EVENTS_PIVOT,
                label:         'Feed events',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) { return feedEventUuids(n.getData()).length > 0; });
                },
                summarize: function (nodes) {
                    return { total: sumCounts(nodes, function (d) { return feedEventUuids(d).length; }) };
                },
                fetch: function (nodes, narrowing, ctx) {
                    return fetchFeedEvents(nodes, ctx && ctx.signal);
                }
            };
        }

        // A MISP feed wears the number of its events this event's values are in.
        function declareFeedPotential(node) {
            var n = feedEventUuids(node.getData()).length;
            if (n) node.setPotential(FEED_EVENTS_PIVOT, n);
            return n > 0;
        }

        function declareAllFeedPotential(graph) {
            var any = false;
            graph.getMutableNodes().forEach(function (n) { any = declareFeedPotential(n) || any; });
            if (any) graph.renderer.update();
            graph.on('nodeAdd', declareFeedPotential);
        }

        /* ── pivot: tags and galaxy clusters ───────────────────── */
        // One node per tag or cluster, joined to every element on the canvas that
        // carries it, so two elements sharing a cluster are visibly linked. An
        // object answers for its attributes: MISP tags those, not the object.
        var TAG_PIVOT = 'tags';

        function tagNodeId(t)     { return 'tag:' + t.name; }
        function clusterNodeId(c) { return 'cluster:' + c.tag_name; }

        // Tag data per attribute uuid, for objects whose children are not drawn.
        var _tagIndex = {}, _tagIndexFor = null;
        function attributeTags(uuid) {
            if (_tagIndexFor !== _event) { _tagIndex = {}; _tagIndexFor = _event; }
            if (!(uuid in _tagIndex)) {
                var a = ownAttributeIndex().byUuid[uuid];
                _tagIndex[uuid] = a ? tagFields(a) : {};
            }
            return _tagIndex[uuid];
        }

        // Who carries what, for one node: [{ id, tags, clusters }].
        function carriers(node) {
            return carriersOfData(node.id, node.getData());
        }

        function carriersOfData(id, d) {
            d = d || {};
            if (d.type === 'attribute' || d.type === 'event') {
                return (d.tags || d.clusters) ? [{ id: id, tags: d.tags, clusters: d.clusters }] : [];
            }
            if (d.type === 'object') {
                return (ownAttributeIndex().byObject[d.uuid] || []).map(function (uuid) {
                    var f = attributeTags(uuid);
                    return { id: 'attr:' + uuid, tags: f.tags, clusters: f.clusters };
                }).filter(function (c) { return c.tags || c.clusters; });
            }
            return [];
        }

        // Distinct tags and clusters over some carriers, by node id.
        function labelsOf(list) {
            var out = {};
            list.forEach(function (c) {
                (c.tags || []).forEach(function (t) {
                    if (!out[tagNodeId(t)]) out[tagNodeId(t)] = { tag: t };
                });
                (c.clusters || []).forEach(function (cl) {
                    if (!out[clusterNodeId(cl)]) out[clusterNodeId(cl)] = { cluster: cl };
                });
            });
            return out;
        }

        function carriersOf(nodes) {
            return nodes.reduce(function (all, n) { return all.concat(carriers(n)); }, []);
        }

        function labelCount(node) {
            return Object.keys(labelsOf(carriers(node))).length;
        }

        function labelNode(id, l) {
            if (l.tag) {
                return { id: id, data: { type: 'tag', label: l.tag.name, name: l.tag.name,
                                         colour: l.tag.colour, local: l.tag.local } };
            }
            var c = l.cluster;
            return { id: id, data: { type: 'cluster', label: c.value, value: c.value,
                                     galaxy_type: c.galaxy_type, galaxy_name: c.galaxy_name,
                                     tag_name: c.tag_name, uuid: c.uuid } };
        }

        // A cluster as Relationship::getRelatedElement resolves it.
        function clusterNode(id, rec) {
            var g = rec.Galaxy || {};
            return labelNode(id, { cluster: { tag_name: rec.tag_name, value: rec.value,
                galaxy_type: rec.type || g.type, galaxy_name: g.name, uuid: rec.uuid } });
        }

        function tagEdge(from, to, rel) {
            return { id: 'tagged:' + from + '>' + to, from: from, to: to,
                     data: { kind: 'tag', label: rel || '' } };
        }

        // One carrier's edges to every tag and cluster it carries.
        function tagEdgesOf(c) {
            return (c.tags || []).map(function (t) { return tagEdge(c.id, tagNodeId(t), t.relationship_type); })
                .concat((c.clusters || []).map(function (cl) { return tagEdge(c.id, clusterNodeId(cl)); }));
        }

        // The selection's tags and clusters, each joined to its carriers in the
        // selection and to any other carrier already on the canvas.
        function tagsResult(nodes) {
            var labels = labelsOf(carriersOf(nodes));
            var drawn = _graph ? carriersOf(_graph.getMutableNodes()) : [];
            var edges = [], seen = {};
            carriersOf(nodes).concat(drawn).forEach(function (c) {
                tagEdgesOf(c).forEach(function (e) {
                    if (labels[e.to] && !seen[e.id]) { seen[e.id] = true; edges.push(e); }
                });
            });
            return {
                nodes: Object.keys(labels).map(function (id) { return labelNode(id, labels[id]); }),
                edges: edges
            };
        }

        function tagPivot() {
            return {
                id:            TAG_PIVOT,
                label:         'Tags & clusters',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) { return labelCount(n) > 0; });
                },
                summarize: function (nodes) {
                    return { total: Object.keys(labelsOf(carriersOf(nodes))).length };
                },
                fetch: function (nodes) { return tagsResult(nodes); }
            };
        }

        // Whatever a pivot lands is joined to the tags and clusters already
        // drawn, and a tag or cluster it lands to the carriers already drawn, so
        // the order things reached the canvas in does not decide their links.
        // Each edge rides with the node that lands it.
        function joinDrawnTags(result) {
            if (!_graph || !result || !result.nodes) return result;
            var edges = result.edges = result.edges || [], have = {}, landing = [], labels = {};
            edges.forEach(function (e) { have[e.id] = true; });
            function add(e) { if (!have[e.id]) { have[e.id] = true; edges.push(e); } }
            function drawn(id) { return !!_graph.getMutableNode(id); }
            (function walk(list) {
                list.forEach(function (raw) {
                    if (!drawn(raw.id)) {
                        landing.push(raw);
                        var t = (raw.data || {}).type;
                        if (t === 'tag' || t === 'cluster') labels[raw.id] = true;
                    }
                    walk(raw.children || []);
                });
            })(result.nodes);
            landing.forEach(function (raw) {
                carriersOfData(raw.id, raw.data).forEach(function (c) {
                    tagEdgesOf(c).forEach(function (e) { if (drawn(e.to)) add(e); });
                });
            });
            if (Object.keys(labels).length) {
                carriersOf(_graph.getMutableNodes()).forEach(function (c) {
                    tagEdgesOf(c).forEach(function (e) { if (labels[e.to]) add(e); });
                });
            }
            return result;
        }

        function joiningDrawnTags(def) {
            var fetchResult = def.fetch;
            def.fetch = function () {
                var r = fetchResult.apply(this, arguments);
                return r && typeof r.then === 'function' ? r.then(joinDrawnTags) : joinDrawnTags(r);
            };
            return def;
        }

        /* ── pivot: other events carrying a tag or cluster ─────── */
        // Each event lands as a card joined to the selected tag and cluster nodes
        // it carries. /events/taggedEvents says, per event and tag, whether the
        // event carries it or only one of its attributes does.
        var TAGGED_EVENTS_PIVOT = 'tagged-events';
        var TAGGED_EVENTS_LIMIT = 200;

        function tagNameOf(node) {
            var d = node.getData() || {};
            if (d.type === 'tag') return d.name || null;
            if (d.type === 'cluster') return d.tag_name || null;
            return null;
        }

        function tagNamesOf(nodes) {
            var seen = {};
            return nodes.map(tagNameOf).filter(function (name) {
                if (!name || seen[name]) return false;
                return (seen[name] = true);
            });
        }

        function matchMode(narrowing) {
            return narrowing && narrowing.mode === 'or' ? 'or' : 'and';
        }

        // summarize and fetch ask the same question back to back.
        var _tagged = { key: null, promise: null };
        function fetchTaggedEvents(names, mode, signal) {
            var body = JSON.stringify({ tags: names, mode: mode });
            if (_tagged.key !== body) {
                var promise = fetch(baseurl + '/events/taggedEvents/' + encodeURIComponent(eventId || '0') + '.json', {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: signal,
                    headers: {
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': (window.csrfToken || '')
                    },
                    body: body
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .catch(function (err) {
                    if (_tagged.promise === promise) _tagged = { key: null, promise: null };
                    throw err;
                });
                _tagged = { key: body, promise: promise };
            }
            return _tagged.promise;
        }

        function taggedEventsResult(nodes, payload) {
            var out = [], edges = [];
            ((payload && payload.events) || []).forEach(function (card) {
                var id = 'event:' + card.uuid;
                out.push({ id: id, data: eventNodeData(card) });
                nodes.forEach(function (n) {
                    var how = (card.matched || {})[tagNameOf(n)];
                    if (how) edges.push(tagEdge(id, n.id, how === 'attribute' ? 'via attribute' : ''));
                });
            });
            return { nodes: out, edges: edges };
        }

        function taggedEventsPivot() {
            return {
                id:            TAGGED_EVENTS_PIVOT,
                label:         'Events with this tag',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) { return !!tagNameOf(n); });
                },
                summarize: function (nodes, narrowing, ctx) {
                    var names = tagNamesOf(nodes);
                    return fetchTaggedEvents(names, matchMode(narrowing), ctx && ctx.signal)
                        .then(function (r) {
                            var summary = { total: Math.min(r.total || 0, TAGGED_EVENTS_LIMIT) };
                            if (names.length > 1) {
                                summary.facets = [{ key: 'mode', label: 'Match', type: 'select', options: [
                                    { label: 'All of them', value: 'and' },
                                    { label: 'Any of them', value: 'or' }
                                ] }];
                            }
                            return summary;
                        });
                },
                fetch: function (nodes, narrowing, ctx) {
                    return fetchTaggedEvents(tagNamesOf(nodes), matchMode(narrowing), ctx && ctx.signal)
                        .then(function (r) { return taggedEventsResult(nodes, r); });
                }
            };
        }

        /* ── pivot: a cluster's galaxy relations ───────────────── */
        // The relations stored on the selected cluster, outbound only: each
        // target lands as a cluster node, so it merges with one already drawn.
        var RELATED_CLUSTERS_PIVOT = 'related-clusters';

        var _relations = {};
        function clusterRelations(uuid, signal) {
            if (!_relations[uuid]) {
                _relations[uuid] = fetch(baseurl + '/galaxy_clusters/relatedClusters/' + encodeURIComponent(uuid) + '.json', {
                    credentials: 'same-origin',
                    signal: signal,
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function (payload) { return (payload && payload.relations) || []; })
                .catch(function (err) {
                    delete _relations[uuid];
                    throw err;
                });
            }
            return _relations[uuid];
        }

        function relationsOf(nodes, signal) {
            return Promise.all(nodes.map(function (n) { return clusterRelations(n.getData().uuid, signal); }));
        }

        function relatedClustersResult(nodes, lists) {
            var out = [], edges = [], seen = {};
            nodes.forEach(function (n, i) {
                lists[i].forEach(function (r) {
                    var c = r.cluster || {};
                    if (!c.tag_name) return;
                    var id = clusterNodeId(c);
                    if (id === n.id) return;
                    if (!seen[id]) {
                        seen[id] = true;
                        out.push(labelNode(id, { cluster: { tag_name: c.tag_name, value: c.value,
                            galaxy_type: c.type, galaxy_name: c.galaxy_name, uuid: c.uuid } }));
                    }
                    var eid = 'clrel:' + n.id + '>' + id + ':' + r.relation;
                    if (!seen[eid]) {
                        seen[eid] = true;
                        edges.push({ id: eid, from: n.id, to: id,
                                     data: { kind: 'cluster-relation', label: r.relation || '' } });
                    }
                });
            });
            return { nodes: out, edges: edges };
        }

        function relatedClustersPivot() {
            return {
                id:            RELATED_CLUSTERS_PIVOT,
                label:         'Related clusters',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) {
                        var d = n.getData() || {};
                        return d.type === 'cluster' && !!d.uuid;
                    });
                },
                summarize: function (nodes, narrowing, ctx) {
                    return relationsOf(nodes, ctx && ctx.signal).then(function (lists) {
                        return { total: lists.reduce(function (s, l) { return s + l.length; }, 0) };
                    });
                },
                fetch: function (nodes, narrowing, ctx) {
                    return relationsOf(nodes, ctx && ctx.signal).then(function (lists) {
                        return relatedClustersResult(nodes, lists);
                    });
                }
            };
        }

        /* ── the event's elements, as an origin-less pivot (D4, P0) ── */
        // Everything the canvas does not hold yet: event-level attributes,
        // objects whole, and the tags and clusters the event carries. Its Review
        // tab is the searchable, paged list; ingesting is putting elements on the
        // canvas, and undo takes them back off. A view write, so every viewer
        // gets it.
        var ELEMENT_PIVOT = 'event-elements';

        // Offered in this order; tags and clusters only when asked for, since a
        // plain browse is for the event's contents.
        var ELEMENT_KINDS = [
            { value: 'attribute', label: 'Attributes' },
            { value: 'object',    label: 'Objects' },
            { value: 'tag',       label: 'Tags' },
            { value: 'cluster',   label: 'Galaxy clusters' }
        ];
        var DEFAULT_ELEMENT_KINDS = ['attribute', 'object'];

        // Lower-cased text a search matches against, built once per element. An
        // object answers for its attributes, since it is what gets ingested.
        var _haystacks = {};
        function haystack(c) {
            if (_haystacks[c.id] !== undefined) return _haystacks[c.id];
            var rec = c.rec, parts;
            if (c.kind === 'object') {
                parts = [rec.name, rec['meta-category'], rec.comment];
                (rec.Attribute || []).forEach(function (a) {
                    if (!isDeleted(a)) parts.push(a.value, a.type, a.object_relation);
                });
            } else if (c.kind === 'tag') {
                parts = [rec.name];
            } else if (c.kind === 'cluster') {
                parts = [rec.value, rec.galaxy_name, rec.tag_name];
            } else {
                parts = [rec.value, rec.type, rec.category, rec.comment];
            }
            _haystacks[c.id] = parts.filter(function (p) { return p != null; }).join('\n').toLowerCase();
            return _haystacks[c.id];
        }

        // A tag or cluster has no category, so a category pick leaves it out.
        function elementCategory(c) {
            if (c.kind === 'object') return c.rec['meta-category'] || 'object';
            if (c.kind === 'attribute') return c.rec.category || 'Other';
            return null;
        }

        function elementCandidates() {
            var ev = (_event && _event.Event) || {};
            var drawn = function (id) {
                return !!(_graph && typeof _graph.getMutableNode === 'function' && _graph.getMutableNode(id));
            };
            var out = [];
            (ev.Attribute || []).forEach(function (a) {
                var id = 'attr:' + a.uuid;
                if (!isDeleted(a) && !drawn(id)) out.push({ kind: 'attribute', id: id, rec: a });
            });
            (ev.Object || []).forEach(function (o) {
                var id = 'obj:' + o.uuid;
                if (!isDeleted(o) && !drawn(id)) out.push({ kind: 'object', id: id, rec: o });
            });
            var labels = ownLabels();
            Object.keys(labels).forEach(function (id) {
                if (drawn(id)) return;
                var l = labels[id];
                out.push(l.tag ? { kind: 'tag', id: id, rec: l.tag, label: l }
                               : { kind: 'cluster', id: id, rec: l.cluster, label: l });
            });
            return out;
        }

        // `element` is a pick of kinds, the facet's default when absent; an empty
        // pick is nothing. The one-kind string form still reads.
        function elementKinds(narrowing) {
            var e = narrowing.element;
            if (e == null || e === '') return DEFAULT_ELEMENT_KINDS;
            return [].concat(e);
        }

        function matchesNarrowing(c, narrowing) {
            var q = String(narrowing.q || '').trim().toLowerCase();
            if (q && haystack(c).indexOf(q) === -1) return false;
            if (elementKinds(narrowing).indexOf(c.kind) === -1) return false;
            if (narrowing.category && narrowing.category !== elementCategory(c)) return false;
            return true;
        }

        function elementKindOptions(candidates) {
            var counts = {};
            candidates.forEach(function (c) { counts[c.kind] = (counts[c.kind] || 0) + 1; });
            return ELEMENT_KINDS.filter(function (k) { return counts[k.value]; }).map(function (k) {
                return { label: k.label, value: k.value, count: counts[k.value] };
            });
        }

        function countOptions(candidates, keyOf) {
            var counts = {};
            candidates.forEach(function (c) {
                var k = keyOf(c);
                counts[k] = (counts[k] || 0) + 1;
            });
            return Object.keys(counts).sort().map(function (k) {
                return { label: k, value: k, count: counts[k] };
            });
        }

        function elementNode(c) {
            var ev = _event.Event;
            if (c.label) return labelNode(c.id, c.label);
            if (c.kind === 'attribute') {
                return { id: 'attr:' + c.rec.uuid, data: attributeNodeData(c.rec, ownerIn(ev, c.rec)) };
            }
            return {
                id:       'obj:' + c.rec.uuid,
                data:     objectNodeData(c.rec, ownerIn(ev, c.rec)),
                children: (c.rec.Attribute || []).filter(function (a) { return !isDeleted(a); })
                    .map(function (a) { return { id: 'attr:' + a.uuid, data: objectChildData(c.rec, a, ownerIn(ev, a)) }; })
            };
        }

        function elementPivot() {
            return {
                id:            ELEMENT_PIVOT,
                label:         'Event elements',
                origin:        'none',
                maxCandidates: NODE_BUDGET,
                summarize: function (nodes, narrowing) {
                    var all = elementCandidates();
                    narrowing = narrowing || {};
                    return {
                        total: all.filter(function (c) { return matchesNarrowing(c, narrowing); }).length,
                        facets: [
                            { key: 'q', label: 'Search', type: 'text' },
                            { key: 'element', label: 'Element', type: 'multiselect',
                              options: elementKindOptions(all), default: DEFAULT_ELEMENT_KINDS },
                            { key: 'category', label: 'Category', type: 'select',
                              options: countOptions(all.filter(elementCategory), elementCategory) }
                        ]
                    };
                },
                fetch: function (nodes, narrowing) {
                    narrowing = narrowing || {};
                    return {
                        nodes: elementCandidates()
                            .filter(function (c) { return matchesNarrowing(c, narrowing); })
                            .map(elementNode),
                        edges: []
                    };
                }
            };
        }

        /* ── pivot: around another event's object ──────────────── */
        // The objects one reference away from it in its own event, either
        // direction, with the references between them.
        var SURROUNDINGS_PIVOT = 'object-surroundings';

        function foreignObjectUuid(node) {
            var d = node.getData() || {};
            return (d.type === 'object' && d.scope === 'foreign' && d.uuid) ? d.uuid : null;
        }

        var _surroundings = {};
        function surroundings(uuid, signal) {
            if (!_surroundings[uuid]) {
                _surroundings[uuid] = fetch(baseurl + '/objects/surroundings/' + encodeURIComponent(uuid) + '.json', {
                    credentials: 'same-origin',
                    signal: signal,
                    headers: { 'Accept': 'application/json' }
                })
                .then(function (r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .catch(function (err) {
                    delete _surroundings[uuid];
                    throw err;
                });
            }
            return _surroundings[uuid];
        }

        function surroundingsOf(nodes, signal) {
            return Promise.all(nodes.filter(foreignObjectUuid).map(function (n) {
                return surroundings(foreignObjectUuid(n), signal);
            }));
        }

        function surroundingsResult(payloads) {
            var land = landing();
            payloads.forEach(function (s) {
                mergePriorities(s.ui_priorities);
                var cid = s.event ? land.node(eventCardNode(s.event)) : null;
                (s.objects || []).forEach(function (o) {
                    var owner = provenance(o.event_id, s.event ? s.event.uuid : undefined);
                    var id = land.node(foreignObjectNode(o, owner));
                    if (cid) land.edge(inEventEdge(id, cid));
                });
                (s.references || []).forEach(function (r) {
                    var rel = r.relationship_type || 'related-to';
                    land.edge({ id: 'ref:' + r.uuid, from: 'obj:' + r.object_uuid, to: 'obj:' + r.referenced_uuid,
                                data: { kind: 'object-reference', label: rel, uuid: r.uuid, relationship_type: rel } });
                });
            });
            return land.result();
        }

        function surroundingsPivot() {
            return {
                id:            SURROUNDINGS_PIVOT,
                label:         'Around this object',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) { return nodes.filter(foreignObjectUuid); },
                summarize: function (nodes, narrowing, ctx) {
                    return surroundingsOf(nodes, ctx && ctx.signal).then(function (payloads) {
                        var fresh = {};
                        payloads.forEach(function (s) {
                            (s.objects || []).forEach(function (o) {
                                if (!(_graph && _graph.getMutableNode('obj:' + o.uuid))) fresh[o.uuid] = true;
                            });
                        });
                        return { total: Object.keys(fresh).length };
                    });
                },
                fetch: function (nodes, narrowing, ctx) {
                    return surroundingsOf(nodes, ctx && ctx.signal).then(surroundingsResult);
                }
            };
        }

        /* ── pivots: part of another event, by kind ────────────── */
        // A slice of another MISP event's attributes, landed the way a
        // correlation lands: in its object when it has one. Counted and fetched
        // by MISP, which leaves out what the canvas already holds. A feed's card
        // has no local copy to read.
        var CARD_SLICES = {
            all:     { id: 'card-attributes', label: 'Its attributes',         matched: 'Attributes' },
            ids:     { id: 'card-ids',        label: 'Its IDS indicators',     matched: 'IDS indicators' },
            network: { id: 'card-network',    label: 'Its network indicators', matched: 'Network indicators' }
        };
        var MORE_CORRELATIONS_PIVOT = 'card-correlations';

        function otherEventCard(node) {
            var d = node.getData() || {};
            if (d.type !== 'event' || d._provenance === 'feed' || d.event_id == null) return null;
            return String(d.event_id) === String(eventId) ? null : String(d.event_id);
        }

        // What the canvas holds of that event, so MISP neither counts nor sends it.
        function drawnOf(id) {
            var out = [];
            (_graph ? _graph.getMutableNodes() : []).forEach(function (n) {
                var d = n.getData() || {};
                if ((d.type === 'object' || d.type === 'attribute') && d.uuid && String(d.event_id) === id) out.push(d.uuid);
            });
            return out.sort();
        }

        function cardElementsBody(slice, id, narrowing, count) {
            narrowing = narrowing || {};
            var body = { slice: slice, q: narrowing.q || '', types: narrowing.type || [],
                         category: narrowing.category || '', exclude: drawnOf(id), count: count };
            if (slice !== 'ids' && typeof narrowing.ids === 'boolean') body.ids = narrowing.ids;
            return body;
        }

        // Dropped whenever the canvas changes, which changes every `exclude`.
        var _cardElements = {};
        function cardElements(id, body, signal) {
            var key = id + ' ' + JSON.stringify(body);
            if (!_cardElements[key]) {
                _cardElements[key] = postJson('/events/cardElements/' + encodeURIComponent(id) + '.json', body, signal)
                    .catch(function (err) {
                        delete _cardElements[key];
                        throw err;
                    });
            }
            return _cardElements[key];
        }

        function eachCard(nodes, fn) {
            return Promise.all(nodes.map(otherEventCard).filter(Boolean).map(fn));
        }

        function sumInto(into, counts) {
            Object.keys(counts || {}).forEach(function (k) { into[k] = (into[k] || 0) + counts[k]; });
            return into;
        }

        function countFacet(key, label, type, counts) {
            return { key: key, label: label, type: type, options: Object.keys(counts).sort().map(function (k) {
                return { label: k, value: k, count: counts[k] };
            }) };
        }

        function cardElementsResult(slice, payloads) {
            var land = landing();
            payloads.forEach(function (p) {
                mergePriorities(p.ui_priorities);
                var cid = p.event ? land.node(eventCardNode(p.event)) : null;
                var owner = provenance(p.event ? p.event.id : undefined, p.event ? p.event.uuid : undefined);
                var matched = {};
                (p.matched || []).forEach(function (uuid) { matched[uuid] = true; });
                var mark = function (d) { if (matched[d.uuid]) d.matched = [slice]; return d; };
                Object.keys(p.objects || {}).forEach(function (k) {
                    var n = foreignObjectNode(p.objects[k], owner);
                    n.children.forEach(function (c) { mark(c.data); });
                    land.node(n);
                    if (cid) land.edge(inEventEdge(n.id, cid));
                });
                (p.attributes || []).forEach(function (a) {
                    var id = land.node({ id: 'attr:' + a.uuid, data: mark(attributeNodeData(a, owner)) });
                    if (cid) land.edge(inEventEdge(id, cid));
                });
            });
            return land.result();
        }

        function cardElementsPivot(slice) {
            return {
                id:            CARD_SLICES[slice].id,
                label:         CARD_SLICES[slice].label,
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) { return nodes.filter(otherEventCard); },
                summarize: function (nodes, narrowing, ctx) {
                    return eachCard(nodes, function (id) {
                        return cardElements(id, cardElementsBody(slice, id, narrowing, true), ctx && ctx.signal);
                    }).then(function (counts) {
                        var byType = {}, byCategory = {}, total = 0;
                        counts.forEach(function (c) {
                            total += c.total || 0;
                            sumInto(byType, c.by_type);
                            sumInto(byCategory, c.by_category);
                        });
                        var facets = [{ key: 'q', label: 'Search', type: 'text' }];
                        if (slice !== 'ids') facets.push({ key: 'ids', label: 'IDS only', type: 'boolean' });
                        facets.push(countFacet('type', 'Type', 'multiselect', byType),
                                    countFacet('category', 'Category', 'select', byCategory));
                        return { total: total, facets: facets };
                    });
                },
                fetch: function (nodes, narrowing, ctx) {
                    return eachCard(nodes, function (id) {
                        return cardElements(id, cardElementsBody(slice, id, narrowing, false), ctx && ctx.signal);
                    }).then(function (payloads) { return cardElementsResult(slice, payloads); });
                }
            };
        }

        // Every pair between this event and that one, landed by
        // correlationResult. Counted by what of that event it would draw, so the
        // landing is built once and kept for the run.
        var _moreCorrelations = {};
        function moreCorrelations(id, signal) {
            if (!_moreCorrelations[id]) {
                _moreCorrelations[id] = fetchCorrelated({ event_ids: [id] }, signal).catch(function (err) {
                    delete _moreCorrelations[id];
                    throw err;
                });
            }
            return _moreCorrelations[id];
        }

        function correlatesWithCard(node) {
            var id = otherEventCard(node);
            return !!id && !!_counts && (_counts.events[id] || 0) > 0;
        }

        function moreCorrelationsPivot() {
            return {
                id:            MORE_CORRELATIONS_PIVOT,
                label:         'More correlations with this event',
                maxCandidates: NODE_BUDGET,
                appliesTo: function (nodes) { return nodes.filter(correlatesWithCard); },
                summarize: function (nodes, narrowing, ctx) {
                    var cards = nodes.filter(correlatesWithCard);
                    return eachCard(cards, function (id) {
                        return moreCorrelations(id, ctx && ctx.signal).then(function (r) {
                            return r.nodes.filter(function (n) {
                                return String(n.data.event_id) === id && n.data.type !== 'event'
                                    && !(_graph && _graph.getMutableNode(n.id));
                            }).length;
                        });
                    }).then(function (counts) {
                        return { total: counts.reduce(function (s, n) { return s + n; }, 0) };
                    });
                },
                fetch: function (nodes, narrowing, ctx) {
                    return eachCard(nodes.filter(correlatesWithCard), function (id) {
                        return moreCorrelations(id, ctx && ctx.signal);
                    }).then(function (results) {
                        var land = landing();
                        results.forEach(function (r) {
                            r.nodes.forEach(land.node);
                            r.edges.forEach(land.edge);
                        });
                        return land.result();
                    });
                }
            };
        }

        // The library caches summaries until told otherwise, and what this pivot
        // offers is exactly what the canvas lacks.
        function watchElementPivot(graph) {
            if (!graph || typeof graph.on !== 'function' || !graph.pivots) return;
            var drop = function () {
                graph.pivots.invalidate(ELEMENT_PIVOT);
                graph.pivots.invalidate(SURROUNDINGS_PIVOT);
                Object.keys(CARD_SLICES).forEach(function (k) { graph.pivots.invalidate(CARD_SLICES[k].id); });
                graph.pivots.invalidate(MORE_CORRELATIONS_PIVOT);
                _cardElements = {};
            };
            graph.on('nodeAdd', drop);
            graph.on('nodeRemove', drop);
        }

        /* ── the empty canvas (D11) ────────────────────────────── */
        // A canvas with nothing on it says why, and where the event's contents
        // are. Correlations do not seed the canvas: they are reached from the
        // elements put on it.
        function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }

        // `seededEmpty`: the seed drew nothing. Otherwise the analyst emptied the
        // canvas, and "nothing is related" would be false.
        function emptyStatement(ev, seededEmpty) {
            var attrs = 0, objs = 0;
            (ev.Attribute || []).forEach(function (a) { if (!isDeleted(a)) attrs++; });
            (ev.Object || []).forEach(function (o) { if (!isDeleted(o)) objs++; });
            if (!attrs && !objs) {
                return { title: 'This event has no attributes or objects to draw.', detail: '', action: false };
            }
            var parts = [];
            if (attrs) parts.push(plural(attrs, 'attribute', 'attributes'));
            if (objs)  parts.push(plural(objs, 'object', 'objects'));
            var listed = parts.join(' and ') + (attrs + objs === 1 ? ' is' : ' are')
                         + ' listed under Event elements, to search and add.';
            return seededEmpty ? {
                title:  'Nothing in this event is linked yet',
                detail: 'No object references, analyst relationships or feed and server hits to draw. Its ' + listed
                        + ' Correlations are fetched from the elements on the canvas.',
                action: true
            } : {
                title:  'The canvas is empty',
                detail: 'The event\'s ' + listed,
                action: true
            };
        }

        function emptyStateContent(statement) {
            var box = document.createElement('div');
            var h = document.createElement('div');
            h.className = 'fw-semibold';
            h.textContent = statement.title;
            box.appendChild(h);
            if (statement.detail) {
                var p = document.createElement('div');
                p.className = 'mt-1';
                p.textContent = statement.detail;
                box.appendChild(p);
            }
            if (statement.action) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm btn-primary mt-2';
                btn.textContent = 'Browse event elements';
                btn.addEventListener('click', function () {
                    if (_graph && _graph.UIManager && typeof _graph.UIManager.openPivotMode === 'function') {
                        _graph.UIManager.openPivotMode([], ELEMENT_PIVOT);
                    }
                });
                box.appendChild(btn);
            }
            return box;
        }

        // Pivotick shows the card while the canvas is empty and re-renders it on
        // each appearance; `initial` is whether the graph has ever held a node.
        function emptyStateOption(ev) {
            return {
                render: function (ctx) { return emptyStateContent(emptyStatement(ev, ctx.initial)); }
            };
        }

        /* ── sidebar properties ────────────────────────────────── */
        // The fields an analyst reads an element by, under MISP's names, rather
        // than every data key under its own. Blank fields are left out.
        var KIND_LABELS = {
            'object-reference':     'Object reference',
            'in-event':             'In event',
            'analyst-relationship': 'Analyst relationship',
            'correlation':          'Correlation',
            'feed-correlation':     'Seen in a feed',
            'feed-event':           'Event in a feed',
            'server-correlation':   'Seen on a server',
            'tag':                  'Tagged',
            'cluster-relation':     'Galaxy relation',
            'enrichment':           'Enrichment'
        };

        function field(name, value) {
            return (value == null || value === '') ? null : { name: name, value: String(value) };
        }

        function edgeProperties(edge) {
            var d = edge.getData() || {};
            return [field('Link', KIND_LABELS[d.kind] || d.kind), field('Relationship', d.relationship_type),
                    field('Authors', d.authors), field('UUID', d.uuid)].filter(Boolean);
        }

        /* ── sidebar ───────────────────────────────────────────── */
        // pivot-sidebar-model.js says what a selection is, pivot-sidebar-view.js
        // draws it. The header, the detail and the shared-labels panel of one
        // selection share one view-model; its lazy reads run once and each answer
        // is kept for the next selection that asks the same thing.
        var _reads = {};
        var _sidebar = null;

        function readKey(req) {
            return req.method + ' ' + req.url + (req.body ? ' ' + JSON.stringify(req.body) : '');
        }

        function sidebarRead(req, signal) {
            var init = {
                method: req.method,
                credentials: 'same-origin',
                signal: signal,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            };
            if (req.method === 'POST') {
                init.headers['Content-Type'] = 'application/json';
                init.headers['X-CSRF-Token'] = window.csrfToken || '';
                init.body = JSON.stringify(req.body || {});
            }
            return fetch(baseurl + req.url, init).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
        }

        // correlationCounts leaves out what has none, so this event's own
        // elements read 0 when missing; another event's only once they were asked.
        function knownCorrelations(type, uuid) {
            if (!_counts) return null;
            var own = type === 'object' ? _counts.objects : _counts.attributes;
            var foreign = type === 'object' ? _foreignCounts.objects : _foreignCounts.attributes;
            if (uuid in own) return own[uuid];
            if (uuid in foreign) return foreign[uuid];
            if (_foreignAnswered[uuid]) return 0;
            var index = ownAttributeIndex();
            return (type === 'object' ? index.byObject[uuid] : index.byUuid[uuid]) ? 0 : null;
        }

        function sidebarEnv() {
            var matchedLabels = {};
            Object.keys(CARD_SLICES).forEach(function (k) { matchedLabels[k] = CARD_SLICES[k].matched; });
            return {
                event: _event, eventId: eventId, plan: labelPlan, permitted: permitted,
                uiPriorities: uiPriorities, matchedLabels: matchedLabels,
                correlations: knownCorrelations, valueCard: valueCard, lazy: {}
            };
        }

        function sidebarNode(node) {
            var kids = (node.children || []).map(function (k) { return k.getData ? k.getData() : k.data; })
                .filter(Boolean);
            return { kind: 'node', id: node.id, data: node.getData() || {}, children: kids.length ? kids : undefined };
        }

        function isEdge(element) {
            return !!element && element.from !== undefined && element.to !== undefined;
        }

        function sidebarInput(selection) {
            if (Array.isArray(selection)) {
                return isEdge(selection[0]) ? null : { kind: 'nodes', items: selection.map(sidebarNode) };
            }
            if (isEdge(selection)) {
                return {
                    kind: 'edge', id: selection.id, data: selection.getData() || {},
                    from: { id: selection.from.id, data: selection.from.getData() || {} },
                    to: { id: selection.to.id, data: selection.to.getData() || {} }
                };
            }
            return sidebarNode(selection);
        }

        // The view-model with every answer already known folded in. A read can
        // follow from another's answer (a cluster's tag, then the cluster).
        function buildSidebar(session) {
            var env = sidebarEnv();
            var vm = window.MispPivotSidebar.build(session.input, env);
            for (var round = 0; round < 4; round++) {
                var known = Object.keys(vm.lazy || {}).filter(function (key) {
                    return vm.lazy[key].state === 'pending' && !(key in env.lazy)
                        && readKey(vm.lazy[key].request) in _reads;
                });
                if (!known.length) break;
                known.forEach(function (key) { env.lazy[key] = _reads[readKey(vm.lazy[key].request)]; });
                vm = window.MispPivotSidebar.build(session.input, env);
            }
            return vm;
        }

        function pendingReads(vm) {
            return Object.keys(vm.lazy || {}).map(function (key) { return vm.lazy[key]; })
                .filter(function (l) { return l.state === 'pending'; })
                .map(function (l) { return l.request; });
        }

        function runSidebarReads(session) {
            var reads = pendingReads(session.vm);
            if (!reads.length) return;
            var signal = session.abort.signal;
            Promise.all(reads.map(function (req) {
                return sidebarRead(req, signal).then(function (body) {
                    _reads[readKey(req)] = body;
                }, function () {
                    if (!signal.aborted) _reads[readKey(req)] = false;
                });
            })).then(function () {
                if (signal.aborted) return;
                redrawSidebar(session);
                runSidebarReads(session);
            });
        }

        function redrawSidebar(session) {
            session.vm = buildSidebar(session);
            Object.keys(session.mounts).forEach(function (name) {
                var mount = session.mounts[name];
                if (!mount.el.isConnected) return;
                var next = mount.make();
                mount.el.replaceWith(next);
                mount.el = next;
            });
        }

        function sidebarSession(selection) {
            var input = selection ? sidebarInput(selection) : null;
            var key = input ? [].concat(selection).map(function (e) { return e.id; }).join('|') : null;
            if (_sidebar && _sidebar.key === key) return _sidebar;
            if (_sidebar) _sidebar.abort.abort();
            _sidebar = null;
            if (!input) return null;
            var session = { key: key, input: input, abort: new AbortController(), mounts: {}, fold: null };
            session.vm = buildSidebar(session);
            _sidebar = session;
            runSidebarReads(session);
            return session;
        }

        // What the view drew for this slot; redrawn in place as reads land.
        function mountSidebar(session, name, make) {
            var el = make();
            session.mounts[name] = { el: el, make: make };
            return el;
        }

        function refreshSidebar() {
            if (_sidebar) redrawSidebar(_sidebar);
        }

        // Retry forgets what failed for the drawn selection and reads it again.
        function retrySidebar() {
            if (!_sidebar) return;
            Object.keys(_sidebar.vm.lazy || {}).forEach(function (key) {
                var sig = readKey(_sidebar.vm.lazy[key].request);
                if (_reads[sig] === false) delete _reads[sig];
            });
            redrawSidebar(_sidebar);
            runSidebarReads(_sidebar);
        }

        function sidebarHeader(selection) {
            if (Array.isArray(selection) && isEdge(selection[0])) {
                return window.MispPivotSidebarView.header({ entity: 'multi', card: {
                    count: selection.length, kinds: [{ count: selection.length, entity: 'edge' }]
                } });
            }
            var session = sidebarSession(selection);
            if (!session) return undefined;
            return mountSidebar(session, 'header', function () {
                return window.MispPivotSidebarView.header(session.vm, function (section) {
                    if (session.fold) session.fold.reveal(section);
                });
            });
        }

        // A lone element gets the detail; several go back to pivotick's
        // aggregated table, fed one row per value by nodePropertiesMap.
        function sidebarDetail(selection) {
            if (!selection || Array.isArray(selection)) return undefined;
            var session = sidebarSession(selection);
            if (!session) return undefined;
            return mountSidebar(session, 'detail', function () {
                session.fold = window.MispPivotSidebarView.detail(session.vm);
                return session.fold.el;
            });
        }

        function sidebarRows(node) {
            return window.MispPivotSidebar.propertyRows(sidebarNode(node), sidebarEnv());
        }

        function sharedPanel() {
            return {
                id: 'pe-shared',
                render: function (selection) {
                    if (!Array.isArray(selection) || isEdge(selection[0])) return undefined;
                    var session = sidebarSession(selection);
                    if (!session) return undefined;
                    return mountSidebar(session, 'shared', function () {
                        return window.MispPivotSidebarView.shared(session.vm);
                    });
                }
            };
        }

        /* ── node context menu ─────────────────────────────────── */
        // Placed by the library after its own entries, *Pivot ▸* first among them,
        // and above its delete. MISP's pages open in a new tab, so the canvas the
        // analyst built survives.
        function menuData(element) {
            var n = Array.isArray(element) ? (element.length === 1 ? element[0] : null) : element;
            return (n && typeof n.getData === 'function') ? (n.getData() || {}) : null;
        }

        // Another event's page, for anything on the canvas that belongs to one.
        function foreignEventId(d) {
            if (!d || !d.event_id || String(d.event_id) === String(eventId)) return null;
            return (d.type === 'event' || d.type === 'attribute' || d.type === 'object') ? d.event_id : null;
        }

        function openInTab(path) {
            window.open(baseurl + path, '_blank', 'noopener');
        }

        function copyValue(value) {
            var notifier = _graph && _graph.notifier;
            var clip = window.navigator && window.navigator.clipboard;
            if (!clip) {
                if (notifier) notifier.error('Copy failed', 'This browser gives the page no clipboard.');
                return;
            }
            clip.writeText(value).then(function () {
                if (notifier) notifier.success('Copied', truncate(value, 80));
            }, function () {
                if (notifier) notifier.error('Copy failed', 'The browser refused the clipboard.');
            });
        }

        // Of what a right-click names, the results still waiting to be written.
        function unsavedOf(element) {
            var nodes = Array.isArray(element) ? element : [element];
            return nodes.filter(function (n) {
                return n && _graph.pivots.isSavable(n) && !_graph.pivots.isSaved(n);
            });
        }

        function saveElements(element) {
            _graph.pivots.save({ elements: unsavedOf(element) }, { interactive: true });
        }

        function saveEntry(text) {
            return {
                text:      text,
                iconClass: 'fas fa-save',
                visible:   function (el) { return unsavedOf(el).length > 0; },
                onclick:   function (e, el) { saveElements(el); }
            };
        }

        /* ── "Add to graph": the record behind a node, as IntelGraph names it ── */
        var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
        function graphItemOf(node) {
            if (!node || typeof node.getData !== 'function') return null;
            var d = node.getData() || {};
            var at = node.id.indexOf(':');
            var prefix = node.id.slice(0, at + 1), rest = node.id.slice(at + 1);
            var uuid = d.uuid || (UUID_RE.test(rest) ? rest : null);
            if (prefix === 'event:' && uuid) return { type: 'Event', uuid: uuid, label: d.label || d.info };
            if (prefix === 'obj:' && uuid) return { type: 'Object', uuid: uuid, label: d.label || d.name };
            if (prefix === 'attr:' && uuid) return { type: 'Attribute', uuid: uuid, label: d.value || d.label };
            if (prefix === 'cluster:' && d.uuid) return { type: 'GalaxyCluster', uuid: d.uuid, label: d.label || d.value };
            if (prefix === 'value:' && d.value != null) return { type: 'Value', value: String(d.value), label: String(d.value) };
            return null;
        }

        function graphItems(element) {
            return (Array.isArray(element) ? element : [element]).map(graphItemOf).filter(Boolean);
        }

        // Offered where the page has IntelGraph: to a writer of graphs. The
        // explorer is a graph itself, so it says which graph the add feeds.
        function graphEntry(text) {
            return {
                text:      text,
                title:     'Adds it to your active graph, the one the navbar names',
                iconClass: 'fas fa-circle-nodes',
                visible:   function (el) { return !!window.IntelGraphActions && graphItems(el).length > 0; },
                onclick:   function (e, el) { window.IntelGraphActions.add(graphItems(el)); }
            };
        }

        // The library's own menu for several selected nodes.
        function selectionMenu() {
            return [saveEntry('Save selection'), graphEntry('Add selection to active graph')];
        }

        function nodeMenu() {
            return [
                saveEntry('Save this element'),
                graphEntry('Add to active graph'),
                {
                    text:      'Open its event',
                    iconClass: 'fas fa-external-link-alt',
                    visible:   function (el) { return !!foreignEventId(menuData(el)); },
                    onclick:   function (e, el) { openInTab('/events/view2/' + foreignEventId(menuData(el))); }
                },
                {
                    text:      'Browse feed',
                    iconClass: 'fas fa-rss',
                    visible:   function (el) { var d = menuData(el); return !!d && d.type === 'feed' && !!d.source_id; },
                    onclick:   function (e, el) { openInTab('/feeds/previewIndex/' + menuData(el).source_id); }
                },
                {
                    text:      'Preview in feed',
                    iconClass: 'fas fa-rss',
                    visible:   function (el) { var d = menuData(el); return !!d && !!d.feed_id && !!d.uuid; },
                    onclick:   function (e, el) {
                        var d = menuData(el);
                        openInTab('/feeds/previewEvent/' + d.feed_id + '/' + d.uuid);
                    }
                },
                {
                    text:      'Copy value',
                    iconClass: 'fas fa-copy',
                    visible:   function (el) { var d = menuData(el); return !!d && d.type === 'attribute' && d.value !== ''; },
                    onclick:   function (e, el) { copyValue(menuData(el).value); }
                }
            ];
        }

        /* ── canvas menu: taking fetched correlations back off ─── */
        // Everything the correlation pivot brought, over however many runs; what
        // the seed or the element pivot also vouches for stays.

        function fromCorrelationPivot(element) {
            return element.hasSource(CORRELATION_PIVOT);
        }

        function holdsFetchedCorrelations() {
            return !!_graph && (_graph.getMutableEdges().some(fromCorrelationPivot)
                || _graph.getMutableNodes().some(fromCorrelationPivot));
        }

        function removeFetchedCorrelations() {
            var removed = _graph.removeBySource(CORRELATION_PIVOT);
            _graph.notifier.success('Correlations removed',
                plural(removed.nodes.length, 'element', 'elements') + ' and '
                + plural(removed.edges.length, 'link', 'links')
                + ' off the canvas. Undo puts them back.');
        }

        function canvasMenu() {
            return [{
                text:      'Remove fetched correlations',
                iconClass: 'fas fa-eraser',
                visible:   holdsFetchedCorrelations,
                onclick:   removeFetchedCorrelations
            }];
        }

        /* ── filter panel ──────────────────────────────────────── */
        // Declaring any node facet replaces pivotick's derivation from every data
        // key, so the panel names the ones an analyst filters on. Provenance is
        // here rather than in the legend: it has no colour to sample.
        function distinctOptions(key) {
            return function (graph) {
                var seen = {};
                graph.getMutableNodes().forEach(function (n) {
                    var v = (n.getData() || {})[key];
                    if (v != null && v !== '') seen[String(v)] = true;
                });
                return Object.keys(seen).sort().map(function (v) { return { label: v, value: v }; });
            };
        }

        function nodeFacets() {
            var provenanceFacet = { key: 'scope', label: 'Provenance', type: 'multiselect', options: [
                { label: 'This event',   value: 'self' },
                { label: 'Other events', value: 'foreign' }
            ].concat(canEnrich ? [{ label: 'From enrichment', value: 'module' }] : []) };
            return (hasProvenance ? [provenanceFacet] : []).concat([
                { key: 'type',      label: 'Element',        type: 'multiselect', options: distinctOptions('type') },
                { key: 'category',  label: 'Category',       type: 'multiselect', options: distinctOptions('category') },
                { key: 'attr-type', label: 'Attribute type', type: 'multiselect', options: distinctOptions('attr-type') },
                { key: 'name',      label: 'Object',         type: 'multiselect', options: distinctOptions('name') },
                { key: 'to_ids',    label: 'IDS flag',       type: 'boolean' },
                { key: 'warninglisted', label: 'On a warninglist', type: 'boolean' },
                { key: 'value',     label: 'Value',          type: 'regex' }
            ]);
        }

        /* ── pivot: enrichment modules (enrichment PRD) ─────────── */
        // What an expansion module says about a value, landed beside the node it
        // was asked about. Nothing is written into MISP; the answer is kept by
        // the organisation's store, which the Value Profile reads too.
        var ENRICH_PIVOT = 'enrich';
        // Asked pairs (node × module) per run; stored answers do not count (E10).
        var ENRICH_ASK_CAP = 25;
        var ENRICH_IN_FLIGHT = 5;
        // MISP's --bs-enrichment, lifted to read on the canvas.
        var ENRICH_INK = { light: '#6A6396', dark: '#8C84B5' };
        var ENRICH_PLAIN = { light: '#CED4DA', dark: '#495057' };
        // fa-wand-magic-sparkles reduced to what survives at a badge's size.
        var ENRICH_MARK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
            + '<path fill="#fff" d="M9.5 0c.7 5.6 3.4 8.8 9.5 9.5-6.1.7-8.8 3.9-9.5 9.5'
            + '-.7-5.6-3.4-8.8-9.5-9.5 6.1-.7 8.8-3.9 9.5-9.5z"/>'
            + '<path fill="#fff" d="M19 13.5c.4 3.1 1.9 4.6 5 5-3.1.4-4.6 1.9-5 5'
            + '-.4-3.1-1.9-4.6-5-5 3.1-.4 4.6-1.9 5-5z"/></svg>';

        function isEnrichmentResult(node) {
            var d = node && node.getData ? node.getData() : node;
            return !!d && d.scope === 'module';
        }

        function ago(seconds) {
            var h = Math.round((seconds || 0) / 3600);
            if (h < 1) return 'just now';
            return h < 48 ? h + ' h ago' : Math.round(h / 24) + ' days ago';
        }

        function enrichmentMark(d) {
            var mods = (d.modules && d.modules.length ? d.modules : [d.module]).filter(Boolean);
            var lines = ['From enrichment — ' + mods.join(', '), 'Not in MISP: a module said this'];
            if (d.untyped) lines.push('Untyped: the module gave no type');
            if (d.from_store) lines.push('Stored answer, ' + ago(d.age));
            return { position: 'ne', color: ENRICH_INK[mispTheme()], svgIcon: ENRICH_MARK,
                     title: lines.join('\n') };
        }

        function enrichmentBadges(node) {
            return isEnrichmentResult(node) ? [enrichmentMark(node.getData())] : [];
        }

        // A group speaks for its members, so only an all-results group is marked.
        function groupEnrichmentBadges(info) {
            var members = info.members || [];
            if (!members.length || !members.every(isEnrichmentResult)) return [];
            var modules = [];
            members.forEach(function (n) {
                var d = n.getData() || {};
                (d.modules || [d.module]).forEach(function (m) {
                    if (m && modules.indexOf(m) === -1) modules.push(m);
                });
            });
            return [enrichmentMark({ modules: modules })];
        }

        // FNV-1a over the object's name and sorted (relation, type, value)
        // triples: one record, one node, whichever origin or run returned it (E4).
        function contentHash(s) {
            var h = 0x811c9dc5;
            for (var i = 0; i < s.length; i++) {
                h ^= s.charCodeAt(i);
                h = Math.imul(h, 0x01000193) >>> 0;
            }
            return ('0000000' + h.toString(16)).slice(-8);
        }

        function resultObjectId(module, o) {
            var triples = (o.attributes || []).map(function (a) {
                return [a.relation || '', a.type || '', String(a.value)].join('\u0001');
            }).sort();
            return 'enr-obj:' + module + ':' + contentHash(o.name + '\u0002' + triples.join('\u0002'));
        }

        // The enrichment catalogue, once per page: which modules each type is offered.
        var _enrichTypes = null, _enrichTypesAsked = false;
        function loadEnrichTypes() {
            if (_enrichTypesAsked || !canEnrich) return;
            _enrichTypesAsked = true;
            fetch(baseurl + '/values/enrichmentTypes.json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).then(function (t) {
                _enrichTypes = t;
                if (_graph && _graph.pivots) _graph.pivots.invalidate(ENRICH_PIVOT);
            }).catch(function (err) {
                console.error('[pivot-explorer] enrichment catalogue failed:', err);
            });
        }

        // What a node can be enriched as: [{ value, type }], one per type any
        // module accepts. A result only when MISP holds it, since the engine
        // enriches a value the reader can see.
        function offered(type) {
            return !!type && (_enrichTypes.types[type] || []).length > 0;
        }

        // An object is enriched through its attributes, each an item of its own
        // carrying its relation; `lead` marks the one the object leads with.
        function objectItems(node) {
            var seen = {};
            var kids = (node.children || []).map(function (k) { return k.getData ? k.getData() : k.data; })
                .filter(function (c) {
                    if (!c || c.value == null || !offered(c['attr-type'])) return false;
                    var key = c['attr-type'] + '|' + c.value;
                    return seen[key] ? false : (seen[key] = true);
                })
                .sort(function (a, b) {
                    return (b.ui_priority || 0) - (a.ui_priority || 0) || (b.to_ids ? 1 : 0) - (a.to_ids ? 1 : 0);
                });
            return kids.map(function (c, i) {
                return { value: String(c.value), type: c['attr-type'], relation: c.object_relation || '', lead: i === 0 };
            });
        }

        // What a node can be enriched as: [{ value, type }], one per type any
        // module accepts. A result only when MISP holds it, since the engine
        // enriches a value the reader can see.
        function enrichItems(node) {
            var d = node.getData() || {};
            if (!_enrichTypes) return [];
            if (d.type === 'object') return isEnrichmentResult(d) ? [] : objectItems(node);
            if (d.value == null) return [];
            var types = d.type === 'value' ? (d.types || [])
                : (d.type === 'attribute' && (!isEnrichmentResult(d) || d.known)) ? [d['attr-type']] : [];
            return types.filter(offered).map(function (t) { return { value: String(d.value), type: t }; });
        }

        function itemKey(item) { return item.type + '|' + item.value; }

        // An object's attributes to enrich: the analyst's pick, else each object's lead.
        function pickedItems(node, narrowing) {
            var items = enrichItems(node);
            if (!items.length || items[0].relation === undefined) return items;
            var picked = narrowing && Array.isArray(narrowing.attribute) ? narrowing.attribute : null;
            return items.filter(function (i) { return picked ? picked.indexOf(itemKey(i)) !== -1 : i.lead; });
        }

        function attributeFacet(nodes) {
            var options = [], dflt = [], seen = {};
            nodes.forEach(function (node) {
                enrichItems(node).forEach(function (i) {
                    if (i.relation === undefined || seen[itemKey(i)]) return;
                    seen[itemKey(i)] = true;
                    options.push({ label: (i.relation ? i.relation + ': ' : '') + truncate(i.value, 40), value: itemKey(i) });
                    if (i.lead) dflt.push(itemKey(i));
                });
            });
            return options.length ? { key: 'attribute', label: 'Attribute', type: 'multiselect',
                                      options: options, default: dflt } : null;
        }

        // Per item, the modules to ask: each once per value, under the first of
        // its types that module accepts, and never the profile's `never`.
        function enrichPairs(nodes, narrowing) {
            var pairs = [], seen = {};
            nodes.forEach(function (node) {
                pickedItems(node, narrowing).forEach(function (item) {
                    var never = _enrichTypes.profile.never[item.type] || [];
                    _enrichTypes.types[item.type].forEach(function (module) {
                        var key = module + '|' + item.value;
                        if (never.indexOf(module) !== -1 || seen[key]) return;
                        seen[key] = true;
                        pairs.push({ node: node, item: item, module: module });
                    });
                });
            });
            return pairs;
        }

        var _enrichStored = {};
        function loadStored(items, signal) {
            var missing = items.filter(function (i) { return !_enrichStored[itemKey(i)]; });
            if (!missing.length) return Promise.resolve();
            return postJson('/values/enrichmentStored.json', { items: missing }, signal).then(function (rows) {
                (rows || []).forEach(function (r) { _enrichStored[itemKey(r)] = r.modules || {}; });
            });
        }

        function storedFor(pair) {
            return (_enrichStored[itemKey(pair.item)] || {})[pair.module] || null;
        }

        function storedLabel(module, rows) {
            if (rows.length !== 1) {
                var held = rows.filter(Boolean).length;
                return held ? module + ' — stored for ' + held : module;
            }
            var s = rows[0];
            if (!s) return module;
            var when = ago(Math.floor(Date.now() / 1000) - s.ran_at);
            if (s.state === 'ok') return module + ' — ' + when;
            if (s.state === 'silent') return module + ' — nothing, ' + when;
            return module + ' — ' + (s.state === 'timeout' ? 'timed out' : 'failed') + ' ' + when;
        }

        function enrichModuleFacet(pairs) {
            var byModule = {}, order = [];
            pairs.forEach(function (p) {
                if (!byModule[p.module]) { byModule[p.module] = []; order.push(p.module); }
                byModule[p.module].push(p);
            });
            var ticked = {};
            pairs.forEach(function (p) {
                (_enrichTypes.profile.ticked[p.item.type] || []).forEach(function (m) { ticked[m] = true; });
                var s = storedFor(p);
                if (s && s.fresh) ticked[p.module] = true;
            });
            order.sort();
            return {
                options: order.map(function (m) {
                    var rows = byModule[m].map(storedFor);
                    var total = rows.reduce(function (t, s) { return t + (s && s.state === 'ok' ? s.total : 0); }, 0);
                    return { label: storedLabel(m, rows), value: m, count: total || undefined };
                }),
                default: order.filter(function (m) { return ticked[m]; })
            };
        }

        function pickedModules(narrowing, facet) {
            return narrowing && Array.isArray(narrowing.module) ? narrowing.module : facet.default;
        }

        // Up to ENRICH_IN_FLIGHT at once; each resolves to { pair, run } or { pair, error }.
        function runPairs(pairs, signal) {
            var out = new Array(pairs.length), next = 0;
            function worker() {
                if (next >= pairs.length) return Promise.resolve();
                var i = next++, p = pairs[i], s = storedFor(p);
                return postJson('/values/enrichmentRun.json', {
                    value: p.item.value, type: p.item.type, module: p.module,
                    mode: s && s.fresh ? 'stored' : 'press'
                }, signal).then(function (r) {
                    out[i] = { pair: p, run: (r && r.run) || {} };
                }, function (err) {
                    if (signal && signal.aborted) throw err;
                    out[i] = { pair: p, error: String(err && err.message || err) };
                }).then(worker);
            }
            var workers = [];
            for (var w = 0; w < Math.min(ENRICH_IN_FLIGHT, pairs.length); w++) workers.push(worker());
            return Promise.all(workers).then(function () { return out; });
        }

        var MISSED_WORDS = {
            silent:      'answered with nothing',
            timeout:     'timed out',
            unreachable: 'the module server did not answer',
            refused:     "MISP's enrichment workflow declined the query",
            ineligible:  'not offered for this value'
        };

        function resultOwner(module, run) {
            return { scope: 'module', module: module, modules: [module],
                     from_store: !!run.from_store, age: run.age };
        }

        function resultAttributeData(a, module, run) {
            return Object.assign(attributeNodeData({
                type: a.type, value: a.value, category: a.category, comment: a.comment,
                to_ids: a.to_ids, object_relation: a.relation
            }, resultOwner(module, run)), { known: !!a.known });
        }

        // §5.3: objects closed with their attributes, attributes and legacy
        // elements loose, each joined to its origin by an `enrichment` edge.
        function landEnrichment(answers) {
            var land = landing(), missed = [], answered = 0;
            answers.forEach(function (a) {
                var p = a.pair, module = p.module, origin = p.node.id, run = a.run || {};
                if (a.error || run.state !== 'ok') {
                    missed.push(module + ': ' + (a.error || run.message || MISSED_WORDS[run.state] || run.state));
                    return;
                }
                answered++;
                if (run.capped) missed.push(module + ': ' + run.shown + ' of ' + Number(run.total).toLocaleString() + ' shown');
                function add(n) {
                    var had = land.get(n.id);
                    if (had && had.data.modules.indexOf(module) === -1) had.data.modules.push(module);
                    land.node(n);
                    // From an object, the edge names the attribute that was asked.
                    var label = p.item.relation ? module + ' · ' + p.item.relation : module;
                    land.edge({ id: 'enr:' + origin + '>' + n.id + ':' + module, from: origin, to: n.id,
                                data: { kind: 'enrichment', label: label, module: module, asked: p.item.value } });
                }
                (run.attributes || []).forEach(function (attr) {
                    // A misp_standard module echoes the attribute it was asked about.
                    if (String(attr.value) === p.item.value && attr.type === p.item.type) return;
                    add({ id: 'enr:' + attr.type + ':' + attr.value, data: resultAttributeData(attr, module, run) });
                });
                (run.elements || []).forEach(function (e) {
                    var type = (e.types || [])[0] || 'text';
                    var data = resultAttributeData({ type: type, value: e.value, known: e.known }, module, run);
                    data.untyped = true;
                    data.candidate_types = (e.types || []).slice(1);
                    add({ id: 'enr:' + type + ':' + e.value, data: data });
                });
                (run.objects || []).forEach(function (o) {
                    var id = resultObjectId(module, o);
                    add({
                        id: id,
                        data: Object.assign(objectNodeData({ name: o.name, 'meta-category': o.meta_category },
                                                           resultOwner(module, run)),
                                            { description: o.description }),
                        children: (o.attributes || []).map(function (attr) {
                            return { id: id + ':' + attr.relation + ':' + attr.value,
                                     data: resultAttributeData(attr, module, run) };
                        })
                    });
                });
            });
            return { result: land.result(), missed: missed, answered: answered };
        }

        // RFC 4122 v4; randomUUID needs a secure context, getRandomValues does not.
        function newUuid() {
            var b = window.crypto.getRandomValues(new Uint8Array(16));
            b[6] = (b[6] & 0x0f) | 0x40;
            b[8] = (b[8] & 0x3f) | 0x80;
            var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
            return [h.slice(0, 8), h.slice(8, 12), h.slice(12, 16), h.slice(16, 20), h.slice(20)].join('-');
        }

        var ENRICH_RELATIONSHIP = 'related-to';

        function resultAttributeRecord(d, uuid, comment) {
            return { uuid: uuid, type: d['attr-type'], category: d.category, value: d.value,
                     to_ids: !!d.to_ids, comment: comment || '', object_relation: d.object_relation };
        }

        // A result is tied back to an origin this event holds, as far as
        // a reference can say it; from another event's origin it goes in
        // unattached, its comment naming what was enriched.
        function saveBody(payload) {
            var body = { Attribute: [], Object: [], ObjectReference: [] }, sent = [];
            var pendingChildren = {};
            (payload.children || []).forEach(function (c) { pendingChildren[c.id] = true; });
            payload.nodes.forEach(function (node) {
                var d = node.getData() || {};
                if (!isEnrichmentResult(d)) return;
                var edges = payload.edges.filter(function (e) {
                    return e.to && e.to.id === node.id && (e.getData() || {}).kind === 'enrichment';
                });
                var own = edges.map(function (e) { return e.from; }).filter(function (o) {
                    return o && isOwnElement(o.getData());
                });
                var first = edges.length ? edges[0].getData() : {};
                var comment = d.comment || ('Enrichment: ' + (first.module || d.module)
                                            + (first.asked ? ' on ' + first.asked : ''));
                var uuid = newUuid(), entry = { node: node, uuid: uuid, children: [] };
                if (d.type === 'object') {
                    body.Object.push({
                        uuid: uuid, name: d.name, comment: comment,
                        Attribute: (node.children || []).filter(function (c) { return pendingChildren[c.id]; })
                            .map(function (c) {
                                var cu = newUuid();
                                entry.children.push({ node: c, uuid: cu });
                                return resultAttributeRecord(c.getData() || {}, cu, (c.getData() || {}).comment);
                            }),
                        ObjectReference: own.map(function (o) {
                            return { referenced_uuid: o.getData().uuid, relationship_type: ENRICH_RELATIONSHIP };
                        })
                    });
                } else {
                    var rec = resultAttributeRecord(d, uuid, comment);
                    delete rec.object_relation;
                    body.Attribute.push(rec);
                    own.forEach(function (o) {
                        if (o.getData().type !== 'object') return;
                        body.ObjectReference.push({ object_uuid: o.getData().uuid, referenced_uuid: uuid,
                                                    relationship_type: ENRICH_RELATIONSHIP });
                    });
                }
                sent.push(entry);
            });
            return { body: body, sent: sent };
        }

        // A saved result is this event's own from then on — a plain MISP
        // node, which the reference editor can start from.
        function adoptSaved(entry, uuid, childrenSaved) {
            var ev = (_event && _event.Event) || {};
            var own = Object.assign(provenance(eventId, ev.uuid),
                { module: undefined, modules: undefined, from_store: undefined, age: undefined,
                  untyped: undefined, candidate_types: undefined, known: undefined });
            entry.node.updateData(Object.assign({ uuid: uuid }, own));
            if (entry.node.getData().type === 'object') {
                var kids = childrenSaved ? entry.children : [];
                kids.forEach(function (c) { c.node.updateData(Object.assign({ uuid: c.uuid }, own)); });
                ev.Object = (ev.Object || []).concat([{ uuid: uuid, Attribute: kids.map(function (c) { return { uuid: c.uuid }; }) }]);
            } else {
                ev.Attribute = (ev.Attribute || []).concat([{ uuid: uuid }]);
            }
            _ownIndex = null;
        }

        // The relationship the references are written with, or null when the
        // analyst backs out.
        function askRelationship(ctx) {
            return loadVocabulary().then(function (names) {
                return ctx.promptData({
                    title:       'Save to this event',
                    submitLabel: 'Save',
                    fields:      relationshipFields(names, [])
                });
            }).then(function (values) {
                if (!values) return null;
                return String(values.custom || '').trim()
                    || String(values.relationship_type || '').trim() || ENRICH_RELATIONSHIP;
            });
        }

        function enrichSave(payload, ctx) {
            var built = saveBody(payload);
            if (!built.sent.length) return true;
            var refs = built.body.ObjectReference.slice();
            built.body.Object.forEach(function (o) { refs = refs.concat(o.ObjectReference); });
            var asked = refs.length ? askRelationship(ctx) : Promise.resolve(ENRICH_RELATIONSHIP);
            return asked.then(function (rel) {
                if (rel === null) return { cancelled: true };
                refs.forEach(function (ref) { ref.relationship_type = rel; });
                return writeEnrichment(payload, built, ctx);
            });
        }

        function writeEnrichment(payload, built, ctx) {
            return postJson('/events/saveEnrichment/' + encodeURIComponent(eventId) + '.json',
                            built.body, ctx && ctx.signal).then(function (r) {
                var results = (r && r.results) || {};
                var savedNodeIds = [], canonicalIds = {}, lost = {}, failed = 0;
                built.sent.forEach(function (entry) {
                    var res = results[entry.uuid] || {};
                    var node = entry.node;
                    if (res.state !== 'saved' && res.state !== 'existing') {
                        failed++;
                        lost[node.id] = true;
                        return;
                    }
                    var prefix = node.getData().type === 'object' ? 'obj:' : 'attr:';
                    // An object the event already held keeps its own attributes.
                    var fresh = res.state === 'saved';
                    savedNodeIds.push(node.id);
                    canonicalIds[node.id] = prefix + res.uuid;
                    entry.children.forEach(function (c) {
                        savedNodeIds.push(c.node.id);
                        if (fresh) canonicalIds[c.node.id] = 'attr:' + c.uuid;
                    });
                    adoptSaved(entry, res.uuid, fresh);
                });
                var savedEdgeIds = payload.edges.filter(function (e) { return !(e.to && lost[e.to.id]); })
                    .map(function (e) { return e.id; });
                // Handing the graph its own nodes back is a re-render and a data
                // event, which is what the legend counts on.
                var g = ctx && ctx.graph;
                var adopted = built.sent.filter(function (entry) { return !lost[entry.node.id]; })
                    .map(function (entry) { return entry.node; });
                if (g && adopted.length) g.updateData(adopted);
                refreshSidebar();
                return {
                    savedNodeIds: savedNodeIds,
                    savedEdgeIds: savedEdgeIds,
                    canonicalIds: canonicalIds,
                    message: failed ? (r && r.message) || (failed + ' could not be saved') : undefined
                };
            });
        }

        function enrichPivot() {
            loadEnrichTypes();
            function facetFor(nodes, narrowing) {
                return enrichModuleFacet(enrichPairs(nodes, narrowing));
            }
            return {
                id:            ENRICH_PIVOT,
                label:         'Enrich',
                maxCandidates: NODE_BUDGET,
                // Only a host with an event the viewer may modify can keep them.
                save:          eventId && canEdit ? enrichSave : undefined,
                appliesTo: function (nodes) {
                    return nodes.filter(function (n) { return enrichItems(n).length > 0; });
                },
                // Pivot ▸ Enrich ▸ one row per attribute of an object (pivotick
                // prd/misp/pivot-menu-choices.md).
                menuChoices: function (nodes) {
                    var facet = attributeFacet(nodes);
                    return facet ? facet.options.map(function (o) {
                        return { label: o.label, narrowing: { attribute: [o.value] } };
                    }) : [];
                },
                summarize: function (nodes, narrowing, ctx) {
                    var items = [];
                    nodes.forEach(function (n) { items = items.concat(enrichItems(n)); });
                    return loadStored(items, ctx && ctx.signal).then(function () {
                        var facet = facetFor(nodes, narrowing);
                        var picked = pickedModules(narrowing, facet);
                        // Known only when every picked module has answered this value
                        // before; a module never asked has no count, not a zero.
                        var total = enrichPairs(nodes, narrowing).reduce(function (t, p) {
                            if (t === null || picked.indexOf(p.module) === -1) return t;
                            var s = storedFor(p);
                            return s ? t + (s.state === 'ok' ? s.total : 0) : null;
                        }, 0);
                        var facets = [Object.assign({ key: 'module', label: 'Module', type: 'multiselect' }, facet)];
                        var attributes = attributeFacet(nodes);
                        return { total: total, facets: attributes ? [attributes].concat(facets) : facets };
                    });
                },
                fetch: function (nodes, narrowing, ctx) {
                    var signal = ctx && ctx.signal;
                    var items = [];
                    nodes.forEach(function (n) { items = items.concat(enrichItems(n)); });
                    // A one-click run has no summarize before it: what is fresh is read here too.
                    var pairs;
                    return loadStored(items, signal).then(function () {
                        var picked = pickedModules(narrowing, facetFor(nodes, narrowing));
                        pairs = enrichPairs(nodes, narrowing).filter(function (p) { return picked.indexOf(p.module) !== -1; });
                        var asked = pairs.filter(function (p) { var s = storedFor(p); return !(s && s.fresh); }).length;
                        if (asked > ENRICH_ASK_CAP) {
                            throw new Error(asked + ' module queries would be sent; ' + ENRICH_ASK_CAP
                                + ' at most per run — untick modules or select fewer nodes.');
                        }
                        return runPairs(pairs, signal);
                    }).then(function (answers) {
                        var landed = landEnrichment(answers);
                        if (!landed.answered && landed.missed.length) throw new Error(landed.missed.join(' · '));
                        var g = ctx && ctx.graph;
                        if (landed.missed.length && g && g.notifier) g.notifier.warning('Enrichment', landed.missed.join(' · '));
                        // The store now holds what was just asked, so the counts are re-read.
                        pairs.forEach(function (p) { delete _enrichStored[itemKey(p.item)]; });
                        if (g && g.pivots) g.pivots.invalidate(ENRICH_PIVOT, nodes);
                        return landed.result;
                    });
                }
            };
        }

        function eventPivots() {
            return [elementPivot(), correlationPivot(), feedEventsPivot(), tagPivot(),
                    taggedEventsPivot(), relatedClustersPivot(), surroundingsPivot(),
                    cardElementsPivot('all'), cardElementsPivot('ids'), cardElementsPivot('network'),
                    moreCorrelationsPivot()].concat(canEnrich ? [enrichPivot()] : []);
        }

        /* ── pivotick options ──────────────────────────────────── */
        function graphOptions() {
            return {
                isDirected: true,
                render: {
                    type: 'svg',
                    minLabelFontSize: 8,
                    // Renderer-wide: objects and correlation containers stay closed,
                    // with no expand chevron or Enter shortcut.
                    enableNodeExpansion: false,
                    nodeTypeAccessor: elementOf,
                    groupStyle: groupStyle,
                    groupOutline: groupOutline,
                    // Feeds and servers are drawn by misp-pivot-nodes, like the elements.
                    nodeStyleMap: Object.assign(mispNodeStyles(), otherNodeStyles()),
                    defaultNodeStyle: Object.assign(labelStyle(), { badges: nodeBadges }),
                    // D1's first edge dimension.
                    edgeTypeAccessor: function (edge) {
                        var d = edge.getData ? edge.getData() : null;
                        return d ? d.kind : undefined;
                    },
                    // A moving dash is reserved for what must draw the eye; a kind
                    // opts in with animateDash: true.
                    defaultEdgeStyle: { animateDash: false },
                    edgeStyleMap: {
                        'object-reference':     { strokeColor: '#428bca' },
                        'in-event':             { strokeColor: '#6c737d', strokeWidth: 1, markerEnd: 'none' },
                        'analyst-relationship': { strokeColor: '#f39a1f', dashed: true },
                        'correlation':          { strokeColor: '#888', dashed: true },
                        'feed-correlation':     { strokeColor: window.MispPivotNodes.palette().feed.core, dashed: true },
                        'feed-event':           { strokeColor: window.MispPivotNodes.palette().feed.core },
                        'server-correlation':   { strokeColor: window.MispPivotNodes.palette().server.core, dashed: true },
                        'tag':                  { strokeColor: '#8a8f98', dashed: true },
                        'cluster-relation':     { strokeColor: window.MispPivotNodes.palette().galaxy.core },
                        // Solid: a dash already means a derived link.
                        'enrichment':           { strokeColor: ENRICH_INK[mispTheme()], strokeWidth: 1.25 }
                    },
                    // Draw the relationship_type on every edge (referenced + newly created).
                    defaultLabelStyle: {
                        labelAccessor: function (edge) {
                            var d = edge.getData ? edge.getData() : null;
                            return d ? (d.label || '') : '';
                        }
                    }
                },
                // The link distance seeds the opening frame; auto re-tunes from
                // there, since a seed can be 20 nodes or 1,500 (D7).
                simulation: {
                    physics:        'auto',
                    d3LinkDistance: 200
                },
                // A one-click pivot (a context-menu row, a single-pivot rim badge)
                // lands this many new candidates without Review; more go to Review.
                pivotQuickIngestLimit: 25,
                // Every landing arrives folded, one group per kind; Review offers
                // Ingest loose.
                pivotIngestGrouped: true,
                // Results are saved from the context menu, a node or a selection at
                // a time; the panel's and pane's Save would write a whole run.
                pivotSaveControls: false,
                // No `save`: correlations are derived, never counted unsaved.
                pivots: (host.pivots ? host.pivots(kit) : eventPivots()).map(joiningDrawnTags),
                callbacks: {
                    // Another event is a leaf here (PRD §4) — it cannot expand in
                    // place, so double-click hands the analyst over to its own
                    // event page rather than leaving the node a dead end.
                    onNodeDbclick: function (e, node) {
                        var d = node && node.getData ? node.getData() : null;
                        if (!d || d.type !== 'event') return;
                        if (!d.event_id || String(d.event_id) === String(eventId)) return;
                        window.location.href = baseurl + '/events/view2/' + d.event_id;
                    }
                },
                UI: {
                    mode: 'full',
                    theme: mispTheme(),
                    sidebar: { collapsed: true },
                    // Elements grow into their richer drawing on hover; a group
                    // only explains itself in the library's tooltip.
                    tooltip: {
                        enabled: { nodes: false, edges: false, groups: true },
                        renderGroupExtra: groupTooltipExtra
                    },
                    simplify: simplifyOption(),
                    mainHeader: { render: sidebarHeader },
                    neighborsPanel: { graph: neighborsGraph() },
                    propertiesPanel: {
                        render: sidebarDetail,
                        nodePropertiesMap: sidebarRows,
                        edgePropertiesMap: edgeProperties
                    },
                    contextMenu: {
                        menuNode:      { menu: nodeMenu() },
                        menuSelection: { menu: selectionMenu() },
                        menuCanvas:    { menu: canvasMenu() }
                    },
                    // Only drawing or deleting a relationship reaches MISP, so
                    // creating or editing a node or an edge's data is offered to
                    // nobody. A user who can write neither a reference nor an
                    // analyst relationship gets no write tool at all; notes,
                    // hides and layout stay, being canvas-only.
                    editors: {
                        nodeEditor:  { enabled: false },
                        nodeCreator: { enabled: false },
                        edgeEditor:  { enabled: false },
                        edgeCreator: { enabled: canEdit || canAnalyst },
                        deletion:    { enabled: canEdit || canAnalyst }
                    },
                    // Element keys on nodeTypeAccessor. Relationship names the
                    // edge facet's key, so legend and panel drive one filter.
                    legend: {
                        sections: [
                            { title: 'Element', entries: elementLegendEntries },
                            provenanceSection(),
                            { title: 'Relationship', scope: 'edge', key: 'kind' }
                        ].filter(Boolean)
                    },
                    // The layer switch, then what an edge asserts. The latter is
                    // a pattern box, not a list: references alone use ~143 types
                    // (D1). A regex facet is case-blind, so `by` finds
                    // `Characterized_By` too. Derived edges assert nothing and
                    // carry no relationship_type, so a typed filter hides them.
                    filter: {
                        facets: nodeFacets(),
                        edgeFacets: [
                            { key: 'kind', label: 'Relationship', type: 'multiselect' },
                            { key: 'relationship_type', label: 'Asserts', type: 'regex' }
                        ]
                    }
                }
            };
        }

        // Messages arrive already translated but unescaped (the browser decodes
        // the attribute), so build the node instead of interpolating innerHTML.
        function showError(loaderEl, msg) {
            if (!loaderEl) return;
            var p = document.createElement('p');
            p.className = 'text-danger mb-0';
            var icon = document.createElement('i');
            icon.className = 'fas fa-exclamation-triangle me-2';
            p.appendChild(icon);
            p.appendChild(document.createTextNode(msg));
            loaderEl.innerHTML = '';
            loaderEl.appendChild(p);
        }

        // The canvas takes the window's height below its top, down to its card's
        // own bottom edge; MISP's footer stays below the fold.
        var MIN_GRAPH_HEIGHT = 480;
        function fitGraphHeight(el) {
            if (!el || document.fullscreenElement) return;
            var card = host.cardEl;
            var rect = el.getBoundingClientRect();
            var below = card
                ? card.getBoundingClientRect().bottom - rect.bottom +
                  (parseFloat(window.getComputedStyle(card).marginBottom) || 0)
                : 0;
            var top = rect.top + window.scrollY;
            el.style.height = Math.max(MIN_GRAPH_HEIGHT,
                                       Math.floor(window.innerHeight - top - below)) + 'px';
        }

        function keepGraphFitted(el) {
            var pending = false;
            window.addEventListener('resize', function () {
                if (pending) return;
                pending = true;
                window.requestAnimationFrame(function () {
                    pending = false;
                    fitGraphHeight(el);
                });
            });
        }

        /* ── init ──────────────────────────────────────────────── */
        // The event page's seed: the event, drawn by buildGraphData.
        function loadEvent() {
            // A host that already holds an event-shaped payload hands it over.
            if (host.event) {
                _event = host.event;
                return Promise.resolve({ event: host.event, data: buildGraphData(host.event) });
            }
            return fetch(baseurl + '/events/view/' + eventId + '/includeServerCorrelations:1.json', { credentials: 'same-origin' })
                .then(function (r) {
                    if (!r.ok) throw new Error(r.status);
                    return r.json();
                })
                .then(function (event) {
                    _event = event;
                    return { event: event, data: buildGraphData(event) };
                });
        }

        function eventOptions(opts, event) {
            opts.UI.emptyState = emptyStateOption((event && event.Event) || {});
            opts.UI.extraPanels = [sharedPanel()];
            // Only an event with something to show gets the panel (§8.10).
            if (eventHasAnalystData((event && event.Event) || {})) {
                opts.UI.extraPanels.push(analystPanel());
            }
        }

        // Resolves true once the graph is drawn, false when it could not be.
        function initGraph() {
            if (_initialized) return _ready;
            _initialized = true;

            var loaderEl    = host.loaderEl;
            var containerEl = host.containerEl;

            if (typeof window.Pivotick !== 'function' || !window.MispPivotNodes) {
                showError(loaderEl, text.libMissing);
                _ready = Promise.resolve(false);
                return _ready;
            }

            _ready = (host.load ? host.load(kit) : loadEvent())
                .then(function (seed) {
                    _event   = seed.event || null;
                    var data = seed.data;

                    if (loaderEl)    loaderEl.style.display    = 'none';
                    if (containerEl) {
                        containerEl.style.display = '';
                        if (host.fitHeight !== false) {
                            fitGraphHeight(containerEl);
                            keepGraphFitted(containerEl);
                        }
                    }

                    var editor = (canEdit || canAnalyst) ? createEditor() : null;
                    var opts   = graphOptions();
                    if (editor) Object.assign(opts.callbacks, editor.callbacks);
                    if (host.options) host.options(opts, kit, seed);
                    else eventOptions(opts, _event);
                    ((opts.UI.simplify && opts.UI.simplify.rules) || []).forEach(function (r) {
                        if (r.label) _ruleLabels[r.id || r.kind] = r.label;
                    });

                    _graph = new window.Pivotick(
                        containerEl,
                        data,
                        opts
                    );

                    if (editor) {
                        try { editor.attach(_graph); }
                        catch (e) { console.error('[pivot-explorer] editor attach failed:', e); }
                    }
                    followMispTheme(_graph, _graph.UIManager.getRootContainer());
                    _graph.UIManager.getRootContainer().addEventListener('pivot-sidebar-retry', retrySidebar);
                    watchElementPivot(_graph);
                    if (host.afterMount) {
                        host.afterMount(_graph, kit, seed);
                    } else {
                        declareAllFeedPotential(_graph);
                        loadCorrelationCounts(_graph);
                    }
                    return true;
                })
                .catch(function (err) {
                    console.error('[pivot-explorer] graph build failed:', err);
                    _initialized = false;   // allow a retry on the next tab activation
                    showError(loaderEl, text.loadFailed);
                    return false;
                });
            return _ready;
        }

        /* ══════════════════════════════════════════════════════════
           EDITOR — drawing an object reference in pivotick's Create
           mode: isValidConnection gates the gesture, onBeforeEdgeCreate
           asks for the relationship and persists it before the edge lands.
           Putting an element on the canvas is not an edit; that is the
           element pivot, offered to every viewer.
           ══════════════════════════════════════════════════════════ */
        // Canvas node type → AnalystData::valid_targets name.
        var ANALYST_TYPES = { attribute: 'Attribute', object: 'Object', event: 'Event', cluster: 'GalaxyCluster' };

        // The object_relationships vocabulary, fetched once on first use. A
        // failed fetch leaves only the free-text field, which the server
        // accepts anyway.
        var _vocabulary = null;
        function loadVocabulary() {
            if (_vocabulary) return _vocabulary;
            _vocabulary = fetch(baseurl + '/objectRelationships/index.json', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (rows) {
                return (Array.isArray(rows) ? rows : [])
                    .map(function (r) { return r && r.name; })
                    .filter(function (n) { return typeof n === 'string' && n !== ''; })
                    .sort();
            })
            .catch(function (err) {
                console.error('[pivot-explorer] relationship list failed:', err);
                _vocabulary = null;   // ask again next time
                return [];
            });
            return _vocabulary;
        }

        // Both kinds take the same answer: an analyst relationship's type is
        // free text, so a name from the reference vocabulary is as good there.
        function relationshipFields(names, kinds) {
            var fields = [];
            if (kinds.length > 1) {
                fields.push({
                    key:          'kind',
                    label:        'Link type',
                    type:         'select',
                    options:      kinds.map(function (k) { return { label: KIND_LABELS[k], value: k }; }),
                    defaultValue: kinds[0]
                });
            }
            if (names.length) {
                fields.push({
                    key:          'relationship_type',
                    label:        'Relationship type',
                    type:         'select',
                    options:      names.map(function (n) { return { label: n, value: n }; }),
                    defaultValue: names.indexOf('related-to') !== -1 ? 'related-to' : names[0]
                });
            }
            fields.push({
                key:         'custom',
                label:       names.length ? 'Or a custom one' : 'Relationship type',
                type:        'text',
                placeholder: 'custom relationship'
            });
            return fields;
        }

        function createEditor() {
            var graph = null;

            /* ── notifications (fall back to console) ──────────── */
            function notify(kind, title, msg) {
                var n = graph && graph.notifier;
                if (n && typeof n[kind] === 'function') { n[kind](title, msg); return; }
                console.log('[pivot-explorer] ' + kind + ': ' + title + (msg ? ' — ' + msg : ''));
            }

            /* ── edge creation → what it can be, ask, save ──────── */
            function nodeData(n) {
                return n && typeof n.getData === 'function' ? n.getData() : null;
            }

            // Which link kinds a drawn edge could become (D2b). An object
            // reference is owned by an object and stays inside its event, so both
            // ends must be this event's own elements, and only an attribute or an
            // object can be referenced.
            //
            // An analyst relationship may join any two elements MISP can name,
            // this event's or another's (D8).
            function referenceable(s, t) {
                if (host.canReference) return host.canReference(s, t);
                return isOwnElement(s) && isOwnElement(t);
            }

            function possibleKinds(source, target) {
                var s = nodeData(source) || {}, t = nodeData(target) || {};
                var kinds = [];
                if (canEdit && s.type === 'object'
                    && (t.type === 'object' || t.type === 'attribute') && referenceable(s, t)) {
                    kinds.push('object-reference');
                }
                if (canAnalyst && ANALYST_TYPES[s.type] && ANALYST_TYPES[t.type]
                    && s.uuid && t.uuid && s.uuid !== t.uuid) {
                    kinds.push('analyst-relationship');
                }
                return kinds;
            }

            // A note (no getData) is linking itself, which is not ours to judge.
            function isValidConnection(source, target) {
                if (!source || typeof source.getData !== 'function') return true;
                return possibleKinds(source, target).length > 0;
            }


            // What analystData/add asks of a relationship beyond its type, as its
            // own form does. Pivotick's form has no field that depends on another,
            // so the sharing group is always listed and read only for level 4.
            function sharingFields() {
                var fields = [{
                    key:          'distribution',
                    label:        'Distribution',
                    type:         'select',
                    options:      analystSharing.levels.map(function (l) { return { label: l[1], value: String(l[0]) }; }),
                    defaultValue: String(analystSharing.default)
                }];
                if (analystSharing.sharingGroups.length) {
                    fields.push({
                        key:          'sharing_group_id',
                        label:        'Sharing group (for that distribution)',
                        type:         'select',
                        options:      [{ label: '—', value: '' }].concat(analystSharing.sharingGroups.map(function (g) {
                            return { label: g[1], value: String(g[0]) };
                        })),
                        defaultValue: analystSharing.sharingGroup != null ? String(analystSharing.sharingGroup) : ''
                    });
                }
                fields.push({
                    key:         'authors',
                    label:       'Authors',
                    type:        'text',
                    placeholder: analystSharing.authors || 'your email, if left blank'
                });
                return fields;
            }

            // The sharing an analyst relationship is saved with, or null when the
            // answer cannot be saved: a sharing group level needs its group.
            function sharingFrom(values) {
                var level = values.distribution != null ? String(values.distribution) : String(analystSharing.default);
                var out = { distribution: level };
                if (level === '4') {
                    if (!values.sharing_group_id) {
                        notify('warning', 'No sharing group', 'Pick the sharing group to share it with.');
                        return null;
                    }
                    out.sharing_group_id = String(values.sharing_group_id);
                }
                var authors = String(values.authors || '').trim();
                if (authors) out.authors = authors;
                return out;
            }

            // The edge only lands once the relationship is saved, so the history
            // records it as persisted and a refused save leaves nothing behind.
            // An analyst relationship also asks how it is shared: in the one form
            // when it is the only kind, else in a second once it is chosen.
            function onBeforeEdgeCreate(ctx) {
                if (ctx.kind !== 'edge') return true;
                var kinds = possibleKinds(ctx.source, ctx.target);
                if (!kinds.length) return false;
                var fromData = nodeData(ctx.source);
                var toData   = nodeData(ctx.target);
                var analystOnly = kinds.length === 1 && kinds[0] === 'analyst-relationship';
                var rel, kind;

                return loadVocabulary().then(function (names) {
                    return ctx.promptData({
                        title:       'Add relationship',
                        submitLabel: analystOnly || kinds.length === 1 ? 'Save' : 'Next',
                        fields:      relationshipFields(names, kinds).concat(analystOnly ? sharingFields() : [])
                    });
                }).then(function (values) {
                    if (!values) return false;   // cancelled
                    rel = String(values.custom || '').trim()
                          || String(values.relationship_type || '').trim();
                    if (!rel) {
                        notify('warning', 'No relationship type', 'Pick one or type your own.');
                        return false;
                    }
                    kind = kinds.indexOf(values.kind) !== -1 ? values.kind : kinds[0];
                    if (kind !== 'analyst-relationship' || analystOnly) return values;
                    return ctx.promptData({
                        title:       'Share the relationship',
                        submitLabel: 'Save',
                        fields:      sharingFields()
                    });
                }).then(function (values) {
                    if (!values) return false;   // cancelled, or refused above
                    var sharing = kind === 'analyst-relationship' ? sharingFrom(values) : null;
                    if (kind === 'analyst-relationship' && !sharing) return false;
                    var save = kind === 'object-reference'
                        ? saveReference(fromData, toData, rel)
                        : saveRelationship(fromData, toData, rel, sharing);
                    return save.then(function (saved) {
                        if (!saved) return false;
                        var data = { kind: kind, label: rel, relationship_type: rel };
                        if (saved.uuid) data.uuid = saved.uuid;
                        if (saved.orgc_uuid) data.orgc = saved.orgc_uuid;
                        if (saved.authors) data.authors = saved.authors;
                        notify('success', 'Relationship added', rel);
                        return { accept: true, data: data, persisted: true };
                    });
                });
            }

            // POST to MISP's REST API, resolving the response body or rejecting
            // with MISP's own message.
            function post(path, body) {
                return fetch(baseurl + path, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': (window.csrfToken || '')
                    },
                    body: JSON.stringify(body)
                })
                .then(function (res) {
                    return res.json().catch(function () { return {}; }).then(function (json) {
                        if (res.ok) return json || {};
                        throw new Error((json && (json.errors || json.message)) || ('HTTP ' + res.status));
                    });
                });
            }

            function saveFailed(err) {
                console.error('[pivot-explorer] save failed:', err);
                notify('error', 'Save failed', String(err && err.message || err));
                return null;
            }

            function saveReference(from, to, rel) {
                return post('/objectReferences/add/' + encodeURIComponent(from.uuid) + '.json', {
                    ObjectReference: { referenced_uuid: to.uuid, relationship_type: rel, comment: '' }
                }).then(function (body) { return body.ObjectReference || {}; }, saveFailed);
            }

            function saveRelationship(from, to, rel, sharing) {
                return post('/analystData/add/Relationship/' + encodeURIComponent(from.uuid)
                            + '/' + ANALYST_TYPES[from.type] + '.json', {
                    Relationship: Object.assign({
                        related_object_uuid: to.uuid,
                        related_object_type: ANALYST_TYPES[to.type],
                        relationship_type:   rel
                    }, sharing)
                }).then(function (body) { return body.Relationship || {}; }, saveFailed);
            }

            /* ── deletion → the relationship behind the edge, never an element ── */
            // Only a relationship whose uuid we know can be found again in MISP,
            // and an analyst one only by its creator org (or a site admin), which
            // is the rule MISP applies. Correlations are derived.
            function isDeletable(edge) {
                var d = edge.getData ? edge.getData() : null;
                if (!d || !d.uuid) return false;
                if (d.kind === 'object-reference') {
                    var from = nodeData(edge.from);
                    return canEdit && (host.canReference ? host.canReference(from, from) : isOwnElement(from));
                }
                if (d.kind === 'analyst-relationship') {
                    return canAnalyst && (siteAdmin || (!!orgUuid && d.orgc === orgUuid));
                }
                return false;
            }

            function describeEdge(edge) {
                var d = edge.getData() || {};
                var end = function (n) { return truncate((nodeData(n) || {}).label || n.id, 42); };
                return end(edge.from) + ' → ' + (d.label || 'related-to') + ' → ' + end(edge.to);
            }

            function confirmBody(edges) {
                var shown = edges.slice(0, 3).map(describeEdge);
                if (edges.length > 3) shown.push('and ' + (edges.length - 3) + ' more');
                return 'This deletes ' + (edges.length === 1 ? 'the relationship ' : edges.length + ' relationships ')
                     + 'in MISP: ' + shown.join('; ') + '. It cannot be undone from the graph.';
            }

            // Delete sits beside Hide, so a node is refused outright and an edge
            // is deleted in MISP only after a confirm saying so. The history row
            // is sealed as persisted, and narrowed to what MISP actually deleted.
            function onBeforeDelete(ctx) {
                if (ctx.nodes.length) {
                    notify('warning', 'Elements are not deleted here',
                           'Hide takes one off the canvas; the event view deletes it from MISP.');
                    return false;
                }
                var doomed = ctx.edges.filter(isDeletable);
                if (doomed.length < ctx.edges.length) {
                    notify('info', 'Some relationships stay',
                           'Correlations, tags, and relationships you cannot delete in MISP, stay; hide them instead.');
                }
                if (!doomed.length) return ctx.edges.length ? { accept: true, edges: [] } : true;

                return ctx.confirm({
                    title:        doomed.length === 1 ? 'Delete relationship' : 'Delete relationships',
                    body:         confirmBody(doomed),
                    confirmLabel: 'Delete in MISP',
                    variant:      'danger'
                }).then(function (confirmed) {
                    if (!confirmed) return false;
                    return Promise.all(doomed.map(deleteRelationship)).then(function (results) {
                        var done = doomed.filter(function (e, i) { return results[i]; });
                        if (!done.length) return false;
                        notify('success', done.length === 1 ? 'Relationship deleted' : done.length + ' relationships deleted');
                        return { accept: true, edges: done, persisted: true };
                    });
                });
            }

            // Each the way MISP's own views delete it: a reference soft, so the
            // deletion syncs; an analyst relationship hard, which blocklists it.
            function deleteRelationship(edge) {
                var d = edge.getData();
                var path = d.kind === 'object-reference'
                    ? '/objectReferences/delete/' + encodeURIComponent(d.uuid) + '.json'
                    : '/analystData/delete/Relationship/' + encodeURIComponent(d.uuid) + '.json';
                return post(path, {}).then(function () { return true; }, function (err) {
                    console.error('[pivot-explorer] delete failed:', err);
                    notify('error', 'Delete failed', describeEdge(edge) + ': ' + String(err && err.message || err));
                    return false;
                });
            }

            /* ── public: callbacks (options-time), attach (post-construction) ── */
            return {
                callbacks: {
                    isValidConnection:  isValidConnection,
                    onBeforeEdgeCreate: onBeforeEdgeCreate,
                    onBeforeDelete:     onBeforeDelete
                },
                // The vocabulary is asked for now, so the form opens at once on
                // the first edge drawn.
                attach: function (g) { graph = g; loadVocabulary(); }
            };
        }

        /* ── what a host builds with ───────────────────────────── */
        var kit = {
            baseurl:          baseurl,
            graph:            function () { return _graph; },
            theme:            mispTheme,
            isDeleted:        isDeleted,
            provenance:       provenance,
            landing:          landing,
            mergePriorities:  mergePriorities,
            postJson:         postJson,
            attributeNodeData: attributeNodeData,
            objectChildData:  objectChildData,
            objectNodeData:   objectNodeData,
            eventNodeData:    eventNodeData,
            eventCardNode:    eventCardNode,
            foreignObjectNode: foreignObjectNode,
            clusterNode:      clusterNode,
            inEventEdge:      inEventEdge,
            sourceMap:        sourceMap,
            sourceNodeData:   sourceNodeData,
            sources:          SOURCES,
            eachAnalystRelationship: eachAnalystRelationship,
            relationshipFarEnd: relationshipFarEnd,
            analystNodeId:    analystNodeId,
            mispNodeStyles:   mispNodeStyles,
            otherNodeStyles:  otherNodeStyles,
            labelStyle:       labelStyle,
            sharedPanel:      sharedPanel,
            analystPanel:     analystPanel,
            eventHasAnalystData: eventHasAnalystData,
            pivots: {
                feedEvents:      feedEventsPivot,
                tags:            tagPivot,
                taggedEvents:    taggedEventsPivot,
                relatedClusters: relatedClustersPivot,
                surroundings:    surroundingsPivot,
                cardElements:    cardElementsPivot,
                // Null for a reader who may not run modules.
                enrich:          function () { return canEnrich ? enrichPivot() : null; }
            }
        };

        return { init: initGraph, kit: kit, graph: function () { return _graph; } };
    }

    /* ── boot ──────────────────────────────────────────────── */
    // The element loads this via assetLoader, i.e. ahead of its own markup, so
    // #pe-card is not there yet on a normal page load. Checking readyState also
    // covers the tab arriving after load.
    function readJson(raw, fallback, what) {
        try {
            return JSON.parse(raw || fallback);
        } catch (e) {
            console.error('[pivot-explorer] unreadable ' + what + ':', e);
            return JSON.parse(fallback);
        }
    }

    function boot() {
        var cardEl = document.getElementById('pe-card');
        if (!cardEl) return;

        var d = cardEl.dataset;
        var explorer = createExplorer({
            cardEl:      cardEl,
            loaderEl:    document.getElementById('pivot-explorer-loader'),
            containerEl: document.getElementById('pivot-explorer-graph'),
            config: {
                eventId:        d.peEventId || '',
                baseurl:        d.peBaseurl || '',
                canEdit:        d.peCanEdit === '1',
                canAnalyst:     d.peCanAnalyst === '1',
                analystSharing: readJson(d.peAnalystSharing, '{}', 'analyst sharing options'),
                uiPriorities:   readJson(d.peUiPriorities, '{}', 'object template priorities'),
                labelPlan:      readJson(d.peLabelPlan, 'null', 'analyst profile priorities'),
                permitted:      readJson(d.pePermitted, 'null', 'analyst profile priorities'),
                valueCard:      d.peValueCard === '1',
                canEnrich:      d.peCanEnrich === '1',
                orgUuid:        d.peOrgUuid || '',
                siteAdmin:      d.peSiteAdmin === '1',
                text: {
                    libMissing: d.peLibMissing || 'Graph library failed to load.',
                    loadFailed: d.peLoadFailed || 'Failed to load event graph.'
                }
            }
        });

        // Lazy-load: build the graph only once its tab is actually shown.
        document.addEventListener('shown.bs.tab', function (e) {
            if (e.target && e.target.getAttribute('href') === '#tab-pivot-explorer') {
                explorer.init();
            }
        });

        var pane = document.getElementById('tab-pivot-explorer');
        if (pane && pane.classList.contains('active')) {
            explorer.init();
        }
    }

    window.MispPivotExplorer = { create: createExplorer };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

}());
