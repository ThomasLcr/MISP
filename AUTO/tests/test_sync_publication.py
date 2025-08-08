import unittest
import time
from common import misps_site_admin, misps_org_admin, create_event, publish_immediately, check_response, get_servers_id, extract_server_numbers, find_unidirectional_link, purge_events_and_blocklists


class TestPublicationState(unittest.TestCase):
    def testPublicationOnPush(self):
        """
        Explicitly tests that an event is correctly pushed to linked MISP instances only after it is published on the source instance.

        Steps:
        1. Create an event on the source instance.
        2. Push the event to all linked servers before publication and verify it is NOT present on the targets.
        3. Publish the event immediately on the source instance.
        4. Push the event again to all linked servers.
        5. Verify the event is now present on all linked target instances.
        6. Cleanup all test events and blocklists from all instances.
        """
        # Use the first MISP instance
        source_instance = misps_org_admin[0]

        # Create an event
        event = create_event('Event for push publication')
        event.distribution = 2

        event = source_instance.add_event(event, pythonify=True)
        uuid = event.uuid
        check_response(event)
        self.assertIsNotNone(event.id)

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Push the event to each linked server (before publication)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            check_response(push_response)

        # Verify that the event is NOT yet present on the targets
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertEqual(
                len(search_results), 0,
                f"Event unexpectedly found on MISP_{target_index} before publication"
            )

        # Publish the event immediately (without sending email)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Push the event on each linked server (for consistency)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Confirm the event now exists on each linked server
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after publication"
            )

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testPublicationOnPull(self):
        """
        Explicitly tests that an event is correctly pulled on a unidirectional (pull-only) link between two MISP instances, and only after publication.

        Steps:
        1. Find a unidirectional link between two instances.
        2. Create and publish an event on the source instance.
        3. Pull events on the target instance before publication and verify the event is NOT present.
        4. Publish the event on the source instance.
        5. Pull events again on the target instance and verify the event is now present.
        6. Cleanup all test events and blocklists from all instances.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

        # Create and publish an event on the source
        event_name = f"Event {source_index} for pull on {target_index} on publication"
        event = create_event(event_name)
        event.distribution = 2

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Perform the pull on the target
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id) # Do not specify event ID because it will pull events that are not published yet
        time.sleep(2)  # Allow time for pull to complete
        check_response(pull_result)

        # Confirm the event doesn't exist on the target
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True

        self.assertFalse(found, f"Event found on MISP_{target_index} after pull, but should not be present yet.")

        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)
        # Perform the pull again to get the published event
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Confirm the event now exists on the target
        results = target_instance.search(uuid=uuid)
        if results:
            found = True

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)