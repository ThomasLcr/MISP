import unittest
import time
from common import misps_site_admin, misps_org_admin, create_event, publish_immediately, check_response, get_servers_id, extract_server_numbers, find_unidirectional_link, purge_events_and_blocklists

class TestDistributionLevel(unittest.TestCase):
    def testDistributionLevelOnPush(self):
        """
        Explicitly tests the impact of the event distribution level on push synchronization between MISP instances.

        Distribution levels:
        0 - Your organisation only: The event must NOT be pushed to any other instance.
        1 - This community only: The event must NOT be pushed to any other instance.
        2 - Connected communities: The event MUST be pushed to instances in connected communities.
        3 - All communities: The event MUST be pushed to all instances.

        The test creates an event, changes its distribution level step by step, pushes it, and verifies its presence or absence on target instances according to the distribution level.
        """
        # Use the first MISP instance as source
        source_instance = misps_org_admin[0]
        
        # Create an event with distribution level 0 (Your organisation only)
        event = create_event('Event for distribution level 1')
        event.distribution = 0

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Push the event to each linked server (before publication)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is NOT present on any target instances
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertEqual(
                len(search_results), 0,
                f"Event unexpectedly found on MISP_{target_index} with distribution level 1"
            )

        # Now change the distribution level to 1 (This community only)
        event.distribution = 1
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Push the updated event to each linked server
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is still NOT present on any target instances
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertEqual(
                len(search_results), 0,
                f"Event unexpectedly found on MISP_{target_index} with distribution level 2"
            )

        # Change the distribution level to 2 (Connected communities)
        event.distribution = 2
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Push the updated event to each linked server
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is present on all target instances in connected communities
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} with distribution level 3"
            )

        # Change the distribution level to 3 (All communities)
        event.distribution = 3
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Verify presence on all reachable instances
        for index, target_instance in enumerate(misps_org_admin, start=1):
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{index} with distribution level 3"
            )
        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testDistributionLevelOnPull(self):
        """
        Explicitly tests the impact of the event distribution level on pull synchronization between MISP instances.

        Distribution levels:
        0 - Your organisation only: The event must NOT be pulled by any other instance.
        1 - This community only: The event CAN be pulled by another instance.
        2 - Connected communities: The event MUST be pulled by instances in connected communities.
        3 - All communities: The event MUST be pulled by all instances.

        The test creates an event, changes its distribution level step by step, pulls it, and verifies its presence or absence on target instances according to the distribution level.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

        # Create and publish an event on the source
        event = create_event('Event for downgrade distribution level')
        event.distribution = 0

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull on the target
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        time.sleep(5)  # Allow time for pull to complete
        check_response(pull_result)

        # Confirm the event exist on the target with distribution level 0
        found = False
        results = misps_site_admin[target_index - 1].search(uuid=uuid)  # Need to search on the misps_site_admin because the Your organisation only is related to the organisation of the user who can pull e.g. the site admin
        if results:
            found = True

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # Now change the distribution level to 1 (This community only)
        event.distribution = 1
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Confirm the event now exists on the target
        results = misps_site_admin[target_index - 1].search(uuid=uuid)
        found = False
        if results:
            found = True
        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # Now change the distribution level to 2 (Connected communities)
        event.distribution = 2
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Confirm the event now exists on the target
        results = target_instance.search(uuid=uuid)
        found = False
        if results:
            found = True
        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # Now change the distribution level to 3 (All communities)
        event.distribution = 3
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Verify presence on all reachable instances
        for index, target_instance in enumerate(misps_org_admin, start=1):
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{index} with distribution level 3"
            )

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testDowngradeDistributionLevelOnPush(self):
        """ 
        Explicitly tests the impact of push synchronization on the downgrade of event distribution level.

        Scenarios:
        - If an event is pushed with distribution level 2 (Connected communities), it MUST be present on all target instances with distribution level 1 (This community only).
        - If the event is then updated to distribution level 3 (All communities), it MUST be present on all target instances with distribution level 3.

        The test creates an event, pushes it, checks the downgrade, updates the event, pushes again, and verifies the new distribution level.
        """
        # Use the first MISP instance as source
        source_instance = misps_org_admin[0]

        # Create an event with distribution level 2 (Connected communities)
        event = create_event('Event for downgrade distribution level')
        event.distribution = 2
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Get the server configurations linked to this instance
        servers = misps_site_admin[0].servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Push the event to each linked server (before publication)
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is present on all target instances in connected communities with distribution level 1
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            # Check if the event is present with distribution level 1
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} with distribution level 1"
            )
            for result in search_results:
                self.assertEqual(int(result['Event']['distribution']), 1,
                                 f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

        # Now change the distribution level to 3 (All communities)
        event.distribution = 3
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)

        # Push the updated event to each linked server
        for server_id in servers_id:
            push_response = misps_site_admin[0].server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is still present on all target instances in connected communities with distribution level 3
        for target_index in linked_server_numbers:
            target_instance = misps_org_admin[target_index - 1]
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{target_index} after pushing with distribution level 3"
            )
            for result in search_results:
                self.assertEqual(int(result['Event']['distribution']), 3,
                                 f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")
                
        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)

    def testDowngradeDistributionLevelOnPull(self):
        """ 
        Explicitly tests the impact of pull synchronization on the downgrade of event distribution level.

        Scenarios:
        - If an event is pulled with distribution level 1 (This community only), it MUST be present on all target instances with distribution level 0 (Your organisation only).
        - If an event is pulled with distribution level 2 (Connected communities), it MUST be present on all target instances with distribution level 1 (This community only).
        - If the event is then updated to distribution level 3 (All communities), it MUST be present on all target instances with distribution level 3.

        The test creates an event, pulls it, checks the downgrade, updates the event, pulls again, and verifies the new distribution level.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

        # Create and publish an event on the source
        event_name = f"Event {source_index} for pull on {target_index} on downgrade distribution level"
        event = create_event(event_name)
        event.distribution = 1
        
        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid
        # Publish the event immediately
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2) # Give time for sync propagation

        # Perform the pull on the target
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)
        check_response(pull_result)

        # Confirm the event exists on the target with distribution level 0
        found = False
        results = misps_site_admin[target_index - 1].search(uuid=uuid) # Need to search on the misps_site_admin because the Your organisation only is related to the organisation of the user who can pull e.g. the site admin
        if results:
            found = True
            for result in results:
                self.assertEqual(int(result['Event']['distribution']), 0,
                                    f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 0.")

        # Now change the distribution level to 2 (Connected communities)
        event.distribution = 2
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again to get the updated event
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(1)
        check_response(pull_result)
        # Confirm the event now exists on the target with distribution level 1
        results = target_instance.search(uuid=uuid)
        found = False
        if results:
            found = True
            for result in results:
                self.assertEqual(int(result['Event']['distribution']), 1,
                                    f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 2.")

        # Now change the distribution level to 3 (All communities)
        event.distribution = 3
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again to get the updated event
        pull_result = misps_site_admin[target_index - 1].server_pull(server=server_id, event=event.id)
        time.sleep(2)
        check_response(pull_result)

        # Confirm the event now exists on the target with distribution level 3
        results = target_instance.search(uuid=uuid)
        found = False
        if results:
            found = True
            for result in results:
                self.assertEqual(int(result['Event']['distribution']), 3,
                                    f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 3.")

        # Cleanup: delete all test events on all instances
        for instance in misps_site_admin:
            purge_events_and_blocklists(instance)
