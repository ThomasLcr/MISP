import unittest
import time
from common import misps_site_admin, misps_org_admin, create_event, publish_immediately, check_response, get_servers_id, extract_server_numbers, find_unidirectional_link, purge_events_and_blocklists

class TestModifyEvent(unittest.TestCase):
    def testUpdatedEventnOnPush(self):
        """
        Test that an updated event is correctly propagated to the target instances via push synchronization.
        This test creates an event on the source instance, publishes it, verifies its presence on all linked target instances,
        updates the event, republishes it, pushes the update to all linked servers, and verifies that the update is reflected on all targets.
        Finally, it cleans up all test events and blocklists from all instances.
        """
        # Use the first MISP instance as the source
        source_instance = misps_org_admin[0]

        # Create an event with initial info
        event = create_event('Event before update')
        event.distribution = 2

        # Add the event to the source instance
        event = source_instance.add_event(event, pythonify=True)
        uuid = event.uuid
        check_response(event)
        self.assertIsNotNone(event.id)

        # Publish the event immediately (without sending email notifications)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Retrieve the server configurations linked to the source instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Verify that the event is present on all target instances with the initial info
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after publication"
            )
            for result in search_results:
                self.assertEqual(
                    result['Event']['info'], 'Event before update',
                    f"Event info mismatch on MISP_{target_index}"
                )

        # Update the event info on the source instance
        event.info = 'Event after update'
        updated_event = source_instance.update_event(event, pythonify=True)
        check_response(updated_event)

        # Publish the updated event immediately (without sending email notifications)
        publish_immediately(source_instance, updated_event, with_email=False)
        time.sleep(2)

        # Push the updated event to each linked server for consistency
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=updated_event.id)
            time.sleep(2)  # Allow time for the push operation to complete
            check_response(push_response)

        # Confirm that the updated event exists on each linked server with the new info
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after publication"
            )
            for result in search_results:
                self.assertEqual(
                    result['Event']['info'], 'Event after update',
                    f"Updated event info mismatch on MISP_{target_index}"
                )

        # Cleanup: delete all test events and blocklists from all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testUpdatedEventOnPull(self):
        """
        Test that an updated event is correctly pulled from the source instance to the target instances via pull synchronization.
        This test finds a unidirectional link, creates an event on the source, publishes it, pulls it to the target, verifies its presence,
        updates the event on the source, republishes it, pulls the update to the target, and verifies the update.
        Finally, it cleans up all test events and blocklists from all instances.
        """
        # Find a unidirectional link between two instances
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Unidirectional link: {source_index} --> {target_index}")

        # Create an event on the source instance with a specific name
        event_name = f"Event {source_index} for pull on {target_index}"
        event = create_event(event_name)
        event.distribution = 2

        # Add the event to the source instance
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid

        # Publish the event immediately (without sending email notifications)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Perform the pull operation on the target instance to retrieve the event
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)  # Allow time for the pull operation to complete
        check_response(pull_result)

        # Confirm that the event exists on the target instance with the correct info
        found = False
        results = target_instance.search(uuid=uuid)

        if results:
            found = True
            for result in results:
                self.assertEqual(
                    result['Event']['info'], event_name,
                    f"Event info mismatch on MISP_{target_index}"
                )

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

        # Update the event info on the source instance
        event.info = 'Updated Event after pull'
        updated_event = source_instance.update_event(event, pythonify=True)
        check_response(updated_event)

        # Publish the updated event immediately (without sending email notifications)
        publish_immediately(source_instance, updated_event, with_email=False)
        time.sleep(2)

        # Perform the pull operation again to retrieve the updated event
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=updated_event.id)
        time.sleep(2)  # Allow time for the pull operation to complete
        check_response(pull_result)

        # Confirm that the updated event exists on the target instance with the new info
        results = target_instance.search(uuid=uuid)
        found = False

        if results:
            found = True
            for result in results:
                self.assertEqual(
                    result['Event']['info'], 'Updated Event after pull',
                    f"Event info mismatch on MISP_{target_index}"
                )

        self.assertTrue(found, f"Updated event not found on MISP_{target_index} after pull")

        # Cleanup: delete all test events and blocklists from all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)