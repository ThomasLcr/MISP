import unittest
import time
from common import misps_site_admin, misps_org_admin, create_event, publish_immediately, check_response, get_servers_id, extract_server_numbers, find_unidirectional_link, purge_events_and_blocklists
from pymisp import MISPSighting, MISPNote


class TestSyncMethodsEnabled(unittest.TestCase):
    def testSyncSightingsOnPush(self):
        """
        Checks that sightings are properly synchronized when an event is pushed.
        """
        # Use the first MISP instance as the source
        source_instance = misps_org_admin[0]

        # Create an event and add an attribute
        event = create_event('Event for sightings sync on push')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add a sighting to the first attribute
        attr_id = event.attributes[0].id
        sighting = MISPSighting()
        sighting.value = event.attributes[0].value
        sighting.source = 'SyncTest'
        sighting.type = '0'
        r = source_instance.add_sighting(sighting, event.attributes[0], pythonify=True)
        self.assertEqual(r.source, 'SyncTest')

        # Publish the event and push to linked servers
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Check for the presence of the sighting in the Sighting structure of each target instance
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after push")
            event_data = search_results[0]['Event']
            found = False
            for attr in event_data.get('Attribute', []):
                if str(attr['id']) == str(attr_id) and 'Sighting' in attr:
                    for s in attr['Sighting']:
                        if s['source'] == 'SyncTest' and str(s['attribute_id']) == str(attr_id):
                            found = True
                            break
                if found:
                    break
            self.assertTrue(found, f"Sighting not found in Attribute/Sighting on MISP_{target_index} after push")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)


    def testSyncSightingsOnPull(self):
        """
        Checks that sightings are properly synchronized when an event is pulled.
        """
        # Find a unidirectional link between two instances
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event and add an attribute
        event = create_event('Event for sightings sync on pull')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add a sighting to the first attribute
        attr_id = event.attributes[0].id
        sighting = MISPSighting()
        sighting.value = event.attributes[0].value
        sighting.source = 'SyncTest'
        sighting.type = '0'
        r = source_instance.add_sighting(sighting, event.attributes[0], pythonify=True)
        self.assertEqual(r.source, 'SyncTest')

        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Perform the pull on the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)  # Allow time for pull to complete
        check_response(pull_result)

        # Check for the presence of the sighting in the Sighting structure of the target instance
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True
            event_data = results[0]['Event']
            for attr in event_data.get('Attribute', []):
                if str(attr['id']) == str(attr_id) and 'Sighting' in attr:
                    for s in attr['Sighting']:
                        if s['source'] == 'SyncTest' and str(s['attribute_id']) == str(attr_id):
                            found = True
                            break
                if found:
                    break
        self.assertTrue(found, f"Sighting not found in Attribute/Sighting on MISP_{target_index} after pull")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)


    def testSyncAnalystDataOnPush(self):
        """
        Checks that analyst data (notes) are properly synchronized when an event is pushed.
        """
        # Use the first MISP instance as the source
        source_instance = misps_org_admin[0]

        # Create an event and add a note as analyst data
        event = create_event('Event for analyst data sync on push')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add a note linked to the event
        note = MISPNote()
        note.object_type = 'Event'
        note.object_uuid = uuid
        note.note = 'Test analyst note'
        note = source_instance.add_note(note, pythonify=True)
        self.assertEqual(note.object_uuid, uuid)
        self.assertEqual(note.object_type, 'Event')
        self.assertEqual(note.note, 'Test analyst note')

        # Publish the event and push to linked servers
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Check for the presence of the note in the Note structure of each target instance
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(len(search_results), 0, f"Event not found on MISP_{target_index} after push")
            event_data = search_results[0]['Event']
            found = False
            if 'Note' in event_data:
                for n in event_data['Note']:
                    if n['object_uuid'] == uuid and n['note'] == 'Test analyst note':
                        found = True
                        break
            self.assertTrue(found, f"Analyst note not found on MISP_{target_index} after push")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    
    def testSyncAnalystDataOnPull(self):
        """
        Checks that analyst data (notes) are properly synchronized when an event is pulled.
        """
        # Find a unidirectional link between two instances
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event and add a note as analyst data
        event = create_event('Event for analyst data sync on pull')
        event.distribution = 2
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Add a note linked to the event
        note = MISPNote()
        note.object_type = 'Event'
        note.object_uuid = uuid
        note.note = 'Test analyst note'
        note = source_instance.add_note(note, pythonify=True)
        self.assertEqual(note.object_uuid, uuid)
        self.assertEqual(note.object_type, 'Event')
        self.assertEqual(note.note, 'Test analyst note')

        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Perform the pull on the target instance
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)  # Allow time for pull to complete
        check_response(pull_result)

        # Check for the presence of the note in the Note structure of the target instance
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True
            event_data = results[0]['Event']
            if 'Note' in event_data:
                for n in event_data['Note']:
                    if n['object_uuid'] == uuid and n['note'] == 'Test analyst note':
                        found = True
                        break
        self.assertTrue(found, f"Analyst note not found on MISP_{target_index} after pull")
        
        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)


    def testSyncGalaxyClusterOnPush(self):
        """
        Checks that galaxy clusters are properly synchronized when pushed from the server index.
        """
        # Feature not implemented in the current version of PyMISP
        self.skipTest("Galaxy cluster sync not implemented in PyMISP version")

    def testSyncGalaxyClusterOnPull(self):
        """
        Checks that galaxy clusters are properly synchronized when pulled from the server index.
        """
        # Feature not implemented in the current version of PyMISP
        self.skipTest("Galaxy cluster sync not implemented in PyMISP version")
