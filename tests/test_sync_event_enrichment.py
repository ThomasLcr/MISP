import unittest
import time
import uuid as UUID
from common import misps_site_admin, misps_org_admin, create_event, publish_immediately, check_response, get_servers_id, extract_server_numbers, find_unidirectional_link, purge_events_and_blocklists
from pymisp import MISPAttribute, MISPObject, MISPTag, MISPEventReport, MISPGalaxy, MISPGalaxyCluster

class TestEventEnrichment(unittest.TestCase):
    def testSyncAttributeOnPush(self):
        """
        Checks that MISP attributes are properly synchronized when pushing an event.
        """
        source_instance = misps_org_admin[0]
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        linked_server_numbers = extract_server_numbers(servers)

        if not servers_id or not linked_server_numbers:
            raise Exception("No server configuration found for the source instance")

        # Create the event
        event = create_event('Event for attribute sync on push')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add the attribute
        new_attribute = MISPAttribute()
        new_attribute.value = 'John'
        new_attribute.type = 'first-name'
        new_attribute.category = 'Person'
        new_attribute.to_ids = False
        added_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)
        check_response(added_attribute)
        self.assertIsNotNone(added_attribute.id)

        # Publish and push to linked servers
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)
            check_response(push_response)

        # Check for the attribute on each linked instance
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after push")

            found_attr = False
            for result in search_results:
                attributes = result['Event'].get('Attribute', [])
                for attr in attributes:
                    if attr['type'] == 'first-name' and attr['value'] == 'John':
                        found_attr = True
                        break
            self.assertTrue(found_attr, f"Attribute 'first-name' with value 'John' not found on MISP_{target_index} after push")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncAttributeOnPull(self):
        """
        Checks that MISP attributes are properly synchronized when pulling an event.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event and add an object on the source
        event = create_event(f'Event {source_index} for attribute sync on pull')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add attribute
        new_attribute = MISPAttribute()
        new_attribute.value = 'John'
        new_attribute.type = 'first-name'
        new_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)

        # Publish the event on the source
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Perform the pull from the target
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)
        check_response(pull_result)

        # Search for the event on the target
        search_results = target_instance.search(uuid=uuid)
        self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after pull")
        event_data = search_results[0]['Event']

        # Check for the synchronized attribute
        attributes = event_data.get('Attribute', [])
        found_attribute = any(
            attr['type'] == 'first-name' and attr['value'] == 'John'
            for attr in attributes
        )

        self.assertTrue(found_attribute, f"Attribute 'first-name' with value 'John' not found on MISP_{target_index} after pull")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncObjectOnPush(self):
        """
        Checks that MISP objects are properly synchronized when pushing an event.
        """
        source_instance = misps_org_admin[0]
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        linked_server_numbers = extract_server_numbers(servers)
        if not servers_id or not linked_server_numbers:
            raise Exception("No server configuration found for the source instance")

        # Create an event and add an object
        event = create_event('Event for object sync on push')
        event.distribution = 2
        obj = MISPObject('file')
        obj.add_attribute('filename', 'foo.txt')
        event.add_object(obj)

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Publish the event and push to linked servers
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)
            check_response(push_response)

        # Check for the object and its attribute on each target
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after push")
            event_data = search_results[0]['Event']
            found_object = False
            for obj in event_data.get('Object', []):
                if obj['name'] == 'file':
                    for attr in obj.get('Attribute', []):
                        if attr['type'] == 'filename' and attr['value'] == 'foo.txt':
                            found_object = True
                            break
                if found_object:
                    break
            self.assertTrue(found_object, f"Object 'file' with attribute 'filename:foo.txt' not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncObjectOnPull(self):
        """
        Checks that MISP objects are properly synchronized when pulling an event.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event and add an object on the source
        event = create_event(f'Event {source_index} for object sync on pull')
        event.distribution = 2
        obj = MISPObject('file')
        obj.add_attribute('filename', 'foo.txt')
        event.add_object(obj)

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Publish the event on the source
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Perform the pull on the target
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)
        check_response(pull_result)

        # Check for the object and its attribute on the target
        search_results = target_instance.search(uuid=uuid)
        self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after pull")
        event_data = search_results[0]['Event']
        found_object = False
        for obj in event_data.get('Object', []):
            if obj['name'] == 'file':
                for attr in obj.get('Attribute', []):
                    if attr['type'] == 'filename' and attr['value'] == 'foo.txt':
                        found_object = True
                        break
            if found_object:
                break
        self.assertTrue(found_object, f"Object 'file' with attribute 'filename:foo.txt' not found on MISP_{target_index} after pull")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncTagOnPush(self):
        """
        Checks that global MISP tags are properly synchronized when pushing an event.
        """
        # Use the first MISP instance as source
        source_instance = misps_org_admin[0]
        
        # Create an event
        event = create_event('Event with a global tag')
        event.distribution = 2
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create and add a global tag
        tag = MISPTag()
        tag.name = 'This is a global tag'
        new_global_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_global_tag)

        # Tag the event with the global tag
        source_instance.tag(event, new_global_tag.name)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Allow time for sync propagation

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Verify that the event is present on all target instances in connected communities with the global tag
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after push"
            )
            for result in search_results:
                tags = result['Event']['Tag']
                found_global_tag = False
                for tag in tags:
                    if tag["name"]== new_global_tag.name:
                        found_global_tag = True
                self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

        # Delete the global tag from the source instance
        misps_site_admin[0].delete_tag(new_global_tag)

    def testSyncTagOnPull(self):
        """
        Checks that global MISP tags are properly synchronized when pulling an event.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event on the source instance
        event_name = f"Event {source_index} with a global tag for pull on {target_index}"
        event = create_event(event_name)
        event.distribution = 2
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create and add a global tag
        tag = MISPTag()
        tag.name = 'This is a global tag'
        new_global_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_global_tag)

        # Tag the event with the global tag
        source_instance.tag(event, new_global_tag.name)
        
        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Allow time for sync propagation

        # Perform the pull on the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        time.sleep(2)  # Allow time for pull to complete
        check_response(pull_result)

        # Confirm the event exists on the target instance with the global tag
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True
            for result in results:
                tags = result['Event']['Tag']
                found_global_tag = False
                for tag in tags:
                    if tag["name"] == new_global_tag.name:
                        found_global_tag = True
                self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

        # Delete the global tag from the source instance
        misps_site_admin[0].delete_tag(new_global_tag)

    def testSyncLocalTagOnPush(self):
        """
        Checks that a local tag is NOT propagated to the target instances.
        The event should be pushed to the target instances without the local tag, but with the global tag.
        """
        # Use the first MISP instance as source
        source_instance = misps_org_admin[0]

        # Create an event
        event = create_event('Event with a local tag')
        event.distribution = 2
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create and add a local tag
        tag = MISPTag()
        tag.name = 'This is a local tag'
        tag.local_only = True
        new_local_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_local_tag)

        # Create and add a global tag
        tag = MISPTag()
        tag.name = 'This is not a local tag'
        new_global_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_global_tag)
        
        # Tag the event with both tags (local and global)
        source_instance.tag(event, new_local_tag.name, local=True) # Specify local flag
        source_instance.tag(event, new_global_tag.name)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Allow time for sync propagation

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Verify that the event is present on all target instances in connected communities
        # with the global tag but NOT the local tag
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after push"
            )
            for result in search_results:
                tags = result['Event']['Tag']
                found_local_tag = False
                found_global_tag = False
                for tag in tags:
                    if tag["name"] == new_local_tag.name:
                        found_local_tag = True
                    if tag["name"]== new_global_tag.name:
                        found_global_tag = True
                self.assertFalse(found_local_tag, f"Local tag found on MISP_{target_index}")
                self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

        # Delete both tags from the source instance
        misps_site_admin[0].delete_tag(new_local_tag)
        misps_site_admin[0].delete_tag(new_global_tag)

    def testSyncLocalTagOnPull(self):
        """
        Checks that a local tag is NOT propagated to the target instances when pulling an event.
        The event should be present on the target instance without the local tag, but with the global tag.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event on the source instance
        event_name = f"Event {source_index} with a local tag for pull on {target_index}"
        event = create_event(event_name)
        event.distribution = 2
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create and add a local tag
        tag = MISPTag()
        tag.name = 'This is a local tag'
        tag.local_only = True
        new_local_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_local_tag)

        # Create and add a global tag
        tag = MISPTag()
        tag.name = 'This is not a local tag'
        new_global_tag = source_instance.add_tag(tag, pythonify=True)
        check_response(new_global_tag)

        # Tag the event with both tags (local and global)
        source_instance.tag(event, new_local_tag.name, local=True)  # Specify local flag
        source_instance.tag(event, new_global_tag.name)
        
        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Allow time for sync propagation

        # Perform the pull on the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        time.sleep(2)  # Allow time for pull to complete
        check_response(pull_result)

        # Confirm the event exists on the target instance without the local tag, but with the global tag
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True
            for result in results:
                tags = result['Event']['Tag']
                found_local_tag = False
                found_global_tag = False
                for tag in tags:
                    if tag["name"] == new_local_tag.name:
                        found_local_tag = True
                    if tag["name"] == new_global_tag.name:
                        found_global_tag = True
                self.assertFalse(found_local_tag, f"Local tag found on MISP_{target_index}")
                self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

        # Delete both tags from the source instance
        misps_site_admin[0].delete_tag(new_local_tag)
        misps_site_admin[0].delete_tag(new_global_tag)

    def testSyncEventReportOnPush(self):
        """
        Checks that MISP event reports are properly synchronized when pushing an event.
        """
        source_instance = misps_org_admin[0]

        # Create an event
        event = create_event("Event with Event Report")
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create an Event Report linked to the event
        report = MISPEventReport()
        report.name = "Test Event Report"
        report.content = "# Markdown Report Content"
        report.distribution = 5  # inherit

        report = source_instance.add_event_report(event.id, report, pythonify=True)
        check_response(report)
        self.assertIsNotNone(report.id)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Get linked servers
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")
        linked_server_numbers = extract_server_numbers(servers)

        # Check for the event report on each instance
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            results = target_instance.search(uuid=uuid)
            self.assertGreater(len(results), 0, f"Event not found on MISP_{target_index}")

            for result in results:
                reports = result['Event'].get('EventReport', [])
                found = any(r['name'] == report.name and not r.get('deleted', False) for r in reports)
                self.assertTrue(found, f"Event report not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncEventReportOnPull(self):
        """
        Checks that MISP event reports are properly synchronized when pulling an event.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event
        event = create_event("Event with Event Report (pull test)")
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Create an Event Report
        report = MISPEventReport()
        report.name = "Test Event Report for Pull"
        report.content = "# Markdown Report Content"
        report.distribution = 5

        report = source_instance.add_event_report(event.id, report, pythonify=True)
        check_response(report)
        self.assertIsNotNone(report.id)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Pull from the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        time.sleep(2)
        check_response(pull_result)

        # Check for the event and report on the target
        results = target_instance.search(uuid=uuid)
        self.assertGreater(len(results), 0, f"Event not found on MISP_{target_index}")
        for result in results:
            reports = result['Event'].get('EventReport', [])
            found = any(r['name'] == report.name and not r.get('deleted', False) for r in reports)
            self.assertTrue(found, f"Event report not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncGalaxyClusterOnPush(self):
        """
        Checks that galaxy clusters are properly synchronized when pushing an event.
        """
        source_instance = misps_org_admin[0]

        # Create an event
        event = create_event("Event with Galaxy Cluster")
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        #Create a galaxy (not implemented in PyMisp)
        source_instance._check_response(source_instance._prepare_request(
            'POST',
            '/galaxies/add',
            data={
                'name': 'Galaxy for Push',
                'namespace': 'MISP Test',
                'distribution': 2,
                'description': 'testSyncGalaxyClusterOnPush'
            }
        ))
        new_galaxy = source_instance.galaxies(pythonify=True)[-1]

        # Create a galaxy cluster
        new_uuid = str(UUID.uuid4())
        new_galaxy_cluster: MISPGalaxyCluster = MISPGalaxyCluster()
        new_galaxy_cluster.uuid = new_uuid
        new_galaxy_cluster.value = "Cluster for Push"
        new_galaxy_cluster.authors = ["CIRCL"]
        new_galaxy_cluster.distribution = 2
        new_galaxy_cluster.description = "A cluster description"
        source_instance.add_galaxy_cluster(new_galaxy, new_galaxy_cluster, pythonify=True)

        # Publish the galaxy cluster
        source_instance.publish_galaxy_cluster(new_uuid)
        time.sleep(2)

        # Retrieve a cluster from the first available galaxy
        galaxies: list[MISPGalaxy] = source_instance.galaxies(pythonify=True)
        self.assertGreater(len(galaxies), 0, "No galaxy available")
        galaxy: MISPGalaxy = galaxies[-1]
        galaxy = source_instance.get_galaxy(galaxy.id, withCluster=True, pythonify=True)
        cluster: MISPGalaxyCluster = galaxy.clusters[0]

        # Attach the cluster as a tag to the event
        source_instance.attach_galaxy_cluster(event, cluster)
        event = source_instance.get_event(event.id, pythonify=True)
        self.assertEqual(len(event.galaxies), 1)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Check for the cluster on target instances
        servers = misps_site_admin[0].servers()
        linked_server_numbers = extract_server_numbers(servers)
        self.assertTrue(linked_server_numbers, "No linked instance")

        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            results = target_instance.search(uuid=uuid)
            self.assertGreater(len(results), 0, f"Event not found on MISP_{target_index}")

            for result in results:
                galaxies = result['Event'].get('Galaxy', [])
                found = False
                for galaxy_data in galaxies:
                    for cluster_data in galaxy_data.get('GalaxyCluster', []):
                        # Compare cluster IDs to confirm synchronization
                        if str(cluster_data['uuid']) == str(cluster.uuid):
                            found = True
                            break
                    if found:
                        break
                self.assertTrue(found, f"Galaxy cluster {cluster.uuid} not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testSyncGalaxyClusterOnPull(self):
        """
        Checks that galaxy clusters are properly synchronized when pulling an event.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event
        event = create_event("Event with Galaxy Cluster for pull")
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        #Create a galaxy (not implemented in PyMisp)
        source_instance._check_response(source_instance._prepare_request(
            'POST',
            '/galaxies/add',
            data={
                'name': 'Galaxy for Pull',
                'namespace': 'MISP Test',
                'distribution': 2,
                'description': 'testSyncGalaxyClusterOnPull'
            }
        ))
        new_galaxy = source_instance.galaxies(pythonify=True)[-1]

        # Create a galaxy cluster
        new_uuid = str(UUID.uuid4())
        new_galaxy_cluster: MISPGalaxyCluster = MISPGalaxyCluster()
        new_galaxy_cluster.uuid = new_uuid
        new_galaxy_cluster.value = "Cluster for Pull"
        new_galaxy_cluster.authors = ["CIRCL"]
        new_galaxy_cluster.distribution = 2
        new_galaxy_cluster.description = "A cluster description"
        source_instance.add_galaxy_cluster(new_galaxy, new_galaxy_cluster, pythonify=True)

        # Publish the galaxy cluster
        source_instance.publish_galaxy_cluster(new_uuid)
        time.sleep(2)

        # Retrieve a cluster from the first available galaxy
        galaxies: list[MISPGalaxy] = source_instance.galaxies(pythonify=True)
        self.assertGreater(len(galaxies), 0, "No galaxy available")
        galaxy: MISPGalaxy = galaxies[-1]
        galaxy = source_instance.get_galaxy(galaxy.id, withCluster=True, pythonify=True)
        cluster: MISPGalaxyCluster = galaxy.clusters[0]

        # Attach the cluster as a tag to the event
        source_instance.attach_galaxy_cluster(event, cluster)
        event = source_instance.get_event(event.id, pythonify=True)
        self.assertEqual(len(event.galaxies), 1)

        # Publish the event
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Pull the event on the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        time.sleep(2)
        check_response(pull_result)

        # Check for the cluster on the target instance
        results = target_instance.search(uuid=uuid)
        self.assertGreater(len(results), 0, f"Event not found on MISP_{target_index}")
        for result in results:
            galaxies = result['Event'].get('Galaxy', [])
            found = False
            for galaxy_data in galaxies:
                for cluster_data in galaxy_data.get('GalaxyCluster', []):
                    # Compare cluster IDs to confirm synchronization
                    if str(cluster_data['uuid']) == str(cluster.uuid):
                        found = True
                        break
                if found:
                    break
            self.assertTrue(found, f"Galaxy cluster {cluster.uuid} not found on MISP_{target_index}")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)
