#!/usr/bin/env python3
import os
import json
import uuid
import re
import logging
import inspect
import subprocess
import unittest
import requests
import time
from xml.etree import ElementTree as ET
from io import BytesIO
import urllib3  # type: ignore
from datetime import datetime, timedelta
from typing import Union
from pymisp import PyMISP, MISPOrganisation, MISPUser, MISPRole, MISPSharingGroup, MISPEvent, MISPLog, MISPSighting, Distribution, ThreatLevel, Analysis, MISPEventReport, MISPServerError, MISPAttribute, MISPShadowAttribute, MISPTag
from pymisp.tools import DomainIPObject
from pymisp.api import get_uuid_or_id_from_abstract_misp

# Dynamically get HOST and AUTH variables from environment
hosts = []
auths = []
i = 1
while True:
    host_key = f"HOST_{i}"
    auth_key = f"AUTH_{i}"
    if host_key in os.environ and auth_key in os.environ:
        hosts.append("http://" + os.environ[host_key])
        auths.append(os.environ[auth_key])
        i += 1
    else:
        break

# Create PyMISP connectors for each host/auth pair
misps = [PyMISP(host, auth, ssl=False) for host, auth in zip(hosts, auths)]
print(f"Found {len(misps)} MISP instances.")


def create_event(name: str):
    event = MISPEvent()
    event_uuid = str(uuid.uuid4())
    event.info = name
    event.uuid = event_uuid
    event.distribution = 0  # Set distribution to 'Your organisation' (id 0)
    event.threat_level_id = ThreatLevel.low
    event.analysis = Analysis.completed
    event.add_attribute('text', event_uuid)
    return event


def create_attribute(category: str, value: str):
    attribute = MISPAttribute()
    attribute_uuid = str(uuid.uuid4())
    attribute.category = category
    attribute.value = value
    attribute.uuid = attribute_uuid
    return attribute


def switch_event_distribution(event: MISPEvent, distribution: int):
    """
    Switch the distribution of an event to a new value.
    """
    if not isinstance(event, MISPEvent):
        raise TypeError("Expected a MISPEvent instance.")
    
    event.distribution = distribution
    return event

def extract_server_numbers(servers):
    """
    Extract server numbers from the server names.
    Assumes server names are in the format 'MISP Server X'.
    """
    numbers = []
    for server in servers:
        name = server['Server']['name']
        match = re.search(r'\d+$', name)
        if match:
            numbers.append(int(match.group()))
    return numbers

def get_servers_id(servers):
    """
    Extract server IDs from the server list.
    """
    ids = []
    for server in servers:
        ids.append(server['Server']['id'])
    return ids

def find_unidirectional_link():
    """
    Cherche une paire source/target pour un lien unidirectionnel.
    Retourne (source_instance, target_instance, source_index, target_index, server_id)
    """
    for source_instance in misps:
        source_index = misps.index(source_instance) + 1
        source_links = extract_server_numbers(source_instance.servers())

        for target_index in source_links:
            target_instance = misps[target_index - 1]
            target_links = extract_server_numbers(target_instance.servers())

            # Skip bidirectionnel
            if source_index in target_links:
                continue

            # Si la cible ne pointe pas vers la source → sens normal
            if source_index in source_links and source_index not in target_links:
                pass
            else:
                # Sens inverse
                source_instance, target_instance = target_instance, source_instance
                source_index, target_index = target_index, source_index

            # Trouver l'ID du serveur sur la cible qui pointe vers la source
            server_id = None
            for server in target_instance.servers():
                if str(source_index) in server['Server']['name']:
                    server_id = server['Server']['id']
                    break

            if server_id is None:
                continue

            return source_instance, target_instance, source_index, target_index, server_id

    raise Exception("No unidirectional connection found between any instances.")


def check_response(response):
    if isinstance(response, dict) and "errors" in response:
        raise Exception(response["errors"])
    return response


def request(pymisp: PyMISP, request_type: str, url: str, data: dict = {}) -> dict:
    response = pymisp._prepare_request(request_type, url, data)
    return pymisp._check_response(response)


def publish_immediately(pymisp: PyMISP, event: Union[MISPEvent, int, str, uuid.UUID], with_email: bool = False):
    event_id = get_uuid_or_id_from_abstract_misp(event)
    action = "alert" if with_email else "publish"
    return check_response(request(pymisp, 'POST', f'events/{action}/{event_id}/disable_background_processing:1'))

def unpublish_immediately(pymisp: PyMISP, event: Union[MISPEvent, int, str, uuid.UUID]):
    event_id = get_uuid_or_id_from_abstract_misp(event)
    return check_response(request(pymisp, 'POST', f'events/unpublish/{event_id}/disable_background_processing:1'))


class MISPSetting:
    def __init__(self, admin_connector: PyMISP, new_setting: dict):
        self.admin_connector = admin_connector
        self.new_setting = new_setting

    def __enter__(self):
        self.original = self.__run("modify", json.dumps(self.new_setting).encode("utf-8"))
        # Try to reset config cache
        self.admin_connector.get_server_setting("MISP.live")

    def __exit__(self, exc_type, exc_val, exc_tb):
        self.__run("replace", self.original)
        # Try to reset config cache
        self.admin_connector.get_server_setting("MISP.live")

    @staticmethod
    def __run(command: str, data: bytes) -> bytes:
        dir_path = os.path.dirname(os.path.realpath(__file__))
        r = subprocess.run(["php", dir_path + "/modify_config.php", command, data], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if r.returncode != 0:
            raise Exception([r.returncode, r.stdout, r.stderr])
        return r.stdout

# class TestSyncForAllServers(unittest.TestCase):
#     def testPushForAllServers(self):
#         """
#         Test the push functionality between MISP servers.
#         This test assumes that the MISP instances are already set up and running.
#         """
#         for source_instance in misps:
#             # Get the list of servers configured on this instance
#             linked_servers = extract_server_numbers(source_instance.servers())

#             # Create a new event with unique info
#             event = create_event(f'Push Test Event {misps.index(source_instance) + 1}')
#             event.distribution = 2  # Connected Community
            

#             # Add the event to the source instance
#             event = source_instance.add_event(event, pythonify=True)
#             check_response(event)
#             self.assertIsNotNone(event.id)
#             uuid = event.uuid

#             # Publish immediately to trigger push sync
#             publish_immediately(source_instance, event, with_email=False)
#             time.sleep(2)  # Give time for sync propagation

#             # Verify event presence on expected instances
#             for target_instance in misps:
#                 target_index = misps.index(target_instance) + 1
#                 found_events = target_instance.search(uuid=uuid)

#                 if target_instance == source_instance or target_index in linked_servers:
#                     # Should exist on source and linked servers
#                     self.assertGreater(len(found_events), 0,
#                         f"Event not found on MISP {target_index} but should be present.")
#                 else:
#                     # Should NOT exist on non-linked servers
#                     self.assertEqual(len(found_events), 0,
#                         f"Event found on MISP {target_index} but should NOT be present.")

#         # Cleanup: delete all test events on all instances
#         for instance in misps:
#             for event in instance.search():
#                 instance.delete_event(event['Event']['id'])

#     def testPullForAllServers(self):
#         """
#         Test the pull functionality between MISP servers.
#         Works for any unidirectional sync by inverting source/target if needed.
#         """
#         for source_instance in misps:
#             source_index = misps.index(source_instance) + 1
#             source_links = extract_server_numbers(source_instance.servers())

#             for target_index in source_links:
#                 target_instance = misps[target_index - 1]
#                 target_links = extract_server_numbers(target_instance.servers())

#                 # Skip bidirectional sync to keep this test unidirectional only
#                 if source_index in target_links:
#                     print(f"Skipping bidirectional sync: {source_index} <--> {target_index}")
#                     continue

#                 print(f"Unidirectional link: {source_index} --> {target_index}")

#                 # Determine correct source and target depending on link direction
#                 if source_index in source_links and source_index not in target_links:
#                     # Normal direction
#                     pass
#                 else:
#                     # Inverse direction
#                     source_instance, target_instance = target_instance, source_instance
#                     source_index, target_index = target_index, source_index
#                     print(f"Inverted direction: {source_index} --> {target_index}")

#                 # Find the server ID on the target that links to the source
#                 target_servers = target_instance.servers()
#                 server_id = None
#                 for server in target_servers:
#                     name = server['Server']['name']
#                     if str(source_index) in name:
#                         server_id = server['Server']['id']
#                         break

#                 if server_id is None:
#                     raise Exception(f"No server config on MISP_{target_index} pointing to MISP_{source_index}")

#                 print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

#                 # Create and publish an event on the source
#                 event_name = f"Event {source_index} for pull on {target_index}"
#                 event = create_event(event_name)
#                 event.distribution = 2
                
#                 event = source_instance.add_event(event, pythonify=True)
#                 check_response(event)
#                 self.assertIsNotNone(event.id)
#                 uuid = event.uuid

#                 publish_immediately(source_instance, event)
#                 time.sleep(2)  # Give time for publish propagation

#                 # Perform the pull on the target
#                 pull_result = target_instance.server_pull(server=server_id, event=event.id)
#                 time.sleep(2)  # Allow time for pull to complete
#                 check_response(pull_result)

#                 # Confirm the event exists on the target
#                 found = False
#                 results = target_instance.search(uuid=uuid)
#                 if results:
#                     found = True
#                     break

#                 self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

#                 # Cleanup: delete events on both instances
#                 for e in target_instance.search():
#                     print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
#                     target_instance.delete_event(e['Event']['id'])
#                 for e in source_instance.search():
#                     print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
#                     source_instance.delete_event(e['Event']['id'])


# class TestPublicationState(unittest.TestCase):
#     def testPublicationOnPush(self):
#         """
#         Test that an event is correctly pushed when published on the source instance.
#         """
#         # Use the first MISP instance
#         source_instance = misps[0]

#         # Create an event
#         event = create_event('Event for push publication')
#         event.distribution = 2
        

#         event = source_instance.add_event(event, pythonify=True)
#         uuid = event.uuid
#         check_response(event)
#         self.assertIsNotNone(event.id)

#         # Get the server configurations linked to this instance
#         servers = source_instance.servers()
#         servers_id = get_servers_id(servers)
#         if not servers_id:
#             raise Exception("No server configuration found for the source instance")

#         # Push the event to each linked server (before publication)
#         for server_id in servers_id:
#             push_response = source_instance.server_push(server=server_id, event=event.id)
#             check_response(push_response)

#         # Verify that the event is NOT yet present on the targets
#         linked_server_numbers = extract_server_numbers(servers)
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertEqual(
#                 len(search_results), 0,
#                 f"Event unexpectedly found on MISP_{target_index} before publication"
#             )

#         # Publish the event immediately (without sending email)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)

#         # Push the event on each linked server (kind of useless but for consistency)
#         for server_id in servers_id:
#             push_response = source_instance.server_push(server=server_id, event=event.id)
#             time.sleep(2)  # Allow time for push to complete
#             check_response(push_response)

#         # Confirm the event now exists on each linked server
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after publication"
#             )

#         # Cleanup: delete the event on all linked servers and the source
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             for target_event in target_instance.search():
#                 print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
#                 target_instance.delete_event(target_event['Event']['id'])

#         source_instance.delete_event(event.id)


#     def testPublicationOnPull(self):
#         """
#         Test that an event is correctly pulled on a unidirectional link (pull-only) between two instances.
#         """
#         source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
#         print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

#         # Create and publish an event on the source
#         event_name = f"Event {source_index} for pull on {target_index} on publication"
#         event = create_event(event_name)
#         event.distribution = 2
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid

#         # Perform the pull on the target
#         pull_result = target_instance.server_pull(server=server_id) #Do not specify event ID because it will pull events that are not published yet
#         time.sleep(2)  # Allow time for push to complete
#         check_response(pull_result)

#         # Confirm the event doesn't exists on the target
#         found = False
#         results = target_instance.search(uuid=uuid)
#         if results:
#             found = True

#         self.assertFalse(found, f"Event found on MISP_{target_index} after pull, but should not be present yet.")
        
#         #Publish the event immediately
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)
#         # Perform the pull again to get the published event
#         pull_result = target_instance.server_pull(server=server_id, event=event.id)
#         check_response(pull_result)
#         time.sleep(2)  # Allow time for pull to complete

#         # Confirm the event now exists on the target
#         results = target_instance.search(uuid=uuid)
#         if results:
#             found = True

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

#         # Cleanup: delete events on both instances
#         for e in target_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
#             target_instance.delete_event(e['Event']['id'])
#         for e in source_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
#             source_instance.delete_event(e['Event']['id'])


class TestDistributionLevel(unittest.TestCase):
    def testDistributionLevelOnPush(self):
        """
        Test that the distribution level of an event impact the push behavior.
        0 - Your organisation only : the event should not be pushed to any other instance.
        1 - This community only : the event should not be pushed to any other instance.
        2 - Connected communities : the event should be pushed to instances in connected communities.
        3 - All communities : the event should be pushed to all instances.
        """
        # Use the first MISP instance as source
        source_instance = misps[0]
        
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
        servers = source_instance.servers()
        servers_id = get_servers_id(servers)
        if not servers_id:
            raise Exception("No server configuration found for the source instance")

        # Push the event to each linked server (before publication)
        for server_id in servers_id:
            push_response = source_instance.server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is NOT present on any target instances
        linked_server_numbers = extract_server_numbers(servers)
        for target_index in linked_server_numbers:
            target_instance = misps[target_index - 1]
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
            push_response = source_instance.server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is still NOT present on any target instances
        for target_index in linked_server_numbers:
            target_instance = misps[target_index - 1]
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
            push_response = source_instance.server_push(server=server_id, event=event.id)
            time.sleep(2)  # Allow time for push to complete
            check_response(push_response)

        # Verify that the event is present on all target instances in connected communities
        for target_index in linked_server_numbers:
            target_instance = misps[target_index - 1]
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
        for index, target_instance in enumerate(misps, start=1):
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{index} with distribution level 3"
            )
        # Cleanup
        for index, target_instance in enumerate(misps, start=1):
            for target_event in target_instance.search():
                print(f"Deleting Event {target_event['Event']['id']} on MISP_{index}")
                target_instance.delete_event(target_event['Event']['id'])

        
    def testDistributionLevelOnPull(self):
        """
        Test that the distribution level of an event impact the pull behavior.
        0 - Your organisation only : the event should not be pulled by any other instance.
        1 - This community only : the event should be pulled an other instance.
        2 - Connected communities : the event should be pulled by instances in connected communities.
        3 - All communities : the event should be pulled by all instances.
        """
        source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
        print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

        # Create and publish an event on the source
        event = create_event('Event for distribution level 1')
        event.distribution = 0

        event = source_instance.add_event(event, pythonify=True)
        check_response(event)
        self.assertIsNotNone(event.id)
        uuid = event.uuid
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull on the target
        pull_result = target_instance.server_pull(server=server_id) 
        time.sleep(2)  # Allow time for push to complete
        check_response(pull_result)

        # Confirm the event doesn't exists on the target
        found = False
        results = target_instance.search(uuid=uuid)
        if results:
            found = True

        self.assertFalse(found, f"Event found on MISP_{target_index} after pull, but should not be present yet.")

        # Now change the distribution level to 1 (This community only)
        event.distribution = 1
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = target_instance.server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Confirm the event now exists on the target
        results = target_instance.search(uuid=uuid)
        found = False
        if results:
            found = True
        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # # Remove the event from the target before changing to distribution 2
        # for e in target_instance.search(uuid=uuid):
        #     print(f"Deleting Event {e['Event']['id']} on MISP_{target_index} before distribution 2")
        #     target_instance.delete_event(e['Event']['id'])

        # Now change the distribution level to 2 (Connected communities)
        event.distribution = 2
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = target_instance.server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete

        # Confirm the event now exists on the target
        results = target_instance.search(uuid=uuid)
        found = False
        if results:
            found = True
        self.assertTrue(found, f"Event not found on MISP_{target_index} after pull and publication.")

        # # Remove the event from the target before changing to distribution 3
        # for e in target_instance.search(uuid=uuid):
        #     print(f"Deleting Event {e['Event']['id']} on MISP_{target_index} before distribution 3")
        #     target_instance.delete_event(e['Event']['id'])

        # Now change the distribution level to 3 (All communities)
        event.distribution = 3
        event = source_instance.update_event(event, pythonify=True)
        check_response(event)
        publish_immediately(source_instance, event, with_email=False)
        time.sleep(2)  # Give time for sync propagation

        # Perform the pull again
        pull_result = target_instance.server_pull(server=server_id)
        check_response(pull_result)
        time.sleep(2)  # Allow time for pull to complete



        # Verify presence on all reachable instances
        for index, target_instance in enumerate(misps, start=1):
            search_results = target_instance.search(uuid=uuid)
            self.assertGreater(
                len(search_results), 0,
                f"Event not found on MISP_{index} with distribution level 3"
            )

        # Cleanup
        for index, instance in enumerate(misps, start=1):
            for target_event in instance.search():
                print(f"Deleting Event {target_event['Event']['id']} on MISP_{index}")
                instance.delete_event(target_event['Event']['id'])

#     def testDowngradeDistributionLevelOnPush(self):
#         """ 
#         Test that pushing an event can have an impact on the distribution level.
#         There is 2 scenarios:
#         If an event is pushed with distribution level 2 (Connected communities),
#         it should be present on all target instances with distribution level 1 (This community only).
#         If the event is then updated to distribution level 3 (All communities),
#         it should still be present on all target instances with distribution level 3.
#         """
#         # Use the first MISP instance as source
#         source_instance = misps[0]
        
#         # Create an event with distribution level 3 (Connected communities)
#         event = create_event('Event for downgrade distribution level')
#         event.distribution = 2
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Get the server configurations linked to this instance
#         servers = source_instance.servers()
#         servers_id = get_servers_id(servers)
#         if not servers_id:
#             raise Exception("No server configuration found for the source instance")

#         # Push the event to each linked server (before publication)
#         for server_id in servers_id:
#             push_response = source_instance.server_push(server=server_id, event=event.id)
#             time.sleep(2)  # Allow time for push to complete
#             check_response(push_response)

#         # Verify that the event is present on all target instances in connected communities with distribution level 2
#         linked_server_numbers = extract_server_numbers(servers)
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             # Check if the event is present with distribution level 1
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} with distribution level 1"
#             )
#             for result in search_results:
#                 self.assertEqual(int(result['Event']['distribution']), 1,
#                                  f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

#         # Now change the distribution level to 3 (All communities)
#         event.distribution = 3
#         event = source_instance.update_event(event, pythonify=True)
#         check_response(event)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)

#         # Push the updated event to each linked server
#         for server_id in servers_id:
#             push_response = source_instance.server_push(server=server_id, event=event.id)
#             time.sleep(2)  # Allow time for push to complete
#             check_response(push_response)

#         # Verify that the event is still present on all target instances in connected communities with distribution level 3
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after pushing with distribution level 3"
#             )
#             for result in search_results:
#                 self.assertEqual(int(result['Event']['distribution']), 3,
#                                  f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")
                
#         #Cleanup
#         for index, target_instance in enumerate(misps, start=1):
#             for target_event in target_instance.search():
#                 print(f"Deleting Event {target_event['Event']['id']} on MISP_{index}")
#                 target_instance.delete_event(target_event['Event']['id'])


#     def testDowngradeDistributionLevelOnPull(self):
#         """ 
#         Test that pulling an event can have an impact on the distribution level.
#         There is 3 scenarios:
#         If an event is pulled with distribution level 1 (This community only),
#         it should be present on all target instances with distribution level 0 (Your organisation only).
#         If an event is pulled with distribution level 2 (Connected communities),
#         it should be present on all target instances with distribution level 1 (This community only).
#         If the event is then updated to distribution level 3 (All communities),
#         it should still be present on all target instances with distribution level 3.
#         """
#         source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
#         print(f"Pulling from MISP_{source_index} on MISP_{target_index}")

#         # Create and publish an event on the source
#         event_name = f"Event {source_index} for pull on {target_index} on downgrade distribution level"
#         event = create_event(event_name)
#         event.distribution = 1
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid
#         # Publish the event immediately
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2) # Give time for sync propagation

#         # Perform the pull on the target
#         pull_result = target_instance.server_pull(server=server_id, event=event.id)
#         time.sleep(2)
#         check_response(pull_result)

#         # Confirm the event exists on the target with distribution level 0
#         found = False
#         results = target_instance.search(uuid=uuid)
#         if results:
#             found = True
#             for result in results:
#                 self.assertEqual(int(result['Event']['distribution']), 0,
#                                     f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 0.")

#         # Now change the distribution level to 2 (Connected communities)
#         event.distribution = 2
#         event = source_instance.update_event(event, pythonify=True)
#         check_response(event)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Perform the pull again to get the updated event
#         pull_result = target_instance.server_pull(server=server_id, event=event.id)
#         time.sleep(1)
#         check_response(pull_result)
#         # Confirm the event now exists on the target with distribution level 1
#         results = target_instance.search(uuid=uuid)
#         found = False
#         if results:
#             found = True
#             for result in results:
#                 self.assertEqual(int(result['Event']['distribution']), 1,
#                                     f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 2.")

#         # Now change the distribution level to 3 (All communities)
#         event.distribution = 3
#         event = source_instance.update_event(event, pythonify=True)
#         check_response(event)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Perform the pull again to get the updated event
#         pull_result = target_instance.server_pull(server=server_id, event=event.id)
#         time.sleep(2) 
#         check_response(pull_result)

#         # Confirm the event now exists on the target with distribution level 3
#         results = target_instance.search(uuid=uuid)
#         found = False
#         if results:
#             found = True
#             for result in results:
#                 self.assertEqual(int(result['Event']['distribution']), 3,
#                                     f"Event on MISP_{target_index} has incorrect distribution level {result['Event']['distribution']}")

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with distribution level 3.")

#         # Cleanup: delete events on both instances
#         for e in target_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
#             target_instance.delete_event(e['Event']['id'])
#         for e in source_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
#             source_instance.delete_event(e['Event']['id'])



# class TestLockedStatus(unittest.TestCase):
#     def testLockedStatusOnPush(self):
#         """
#         Test that the locked atribute of an event is correctly set to true when the event is push.
#         The event should not be modified on the target instances.
#         """
#         # Use the first MISP instance as source
#         source_instance = misps[0]
        
#         # Create an event
#         event = create_event('\n Event for locked status on push')
#         event.distribution = 2
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid

#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Get the server configurations linked to this instance
#         servers = source_instance.servers()
#         servers_id = get_servers_id(servers)
#         if not servers_id:
#             raise Exception("No server configuration found for the source instance")
            
#         # Verify that the event is present on each target instance AND that event.locked is set to true
#         linked_server_numbers = extract_server_numbers(servers)
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after push"
#             )
#             for result in search_results:
#                 self.assertTrue(result['Event']['locked'], f"Event on MISP_{target_index} is not locked")
        
#         # Try to update the event on the target instances
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             # Attempt to update the event on the target instance
#             event_to_update = target_instance.get_event(event, pythonify=True)
#             event_to_update.add_attribute('text', 'This should not be allowed')
#             target_instance.update_event(event_to_update, pythonify=True)
#             # Cherck if the event has more than one attribute to ensure it was not modified
#             updated_event = target_instance.search(uuid=uuid)
#             self.assertEqual(
#                 len(updated_event[0]['Event']['Attribute']), 2,
#                 f"Event on MISP_{target_index} was modified despite being locked"
#             )

#         # Cleanup: delete the event on all linked servers and the source
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             for target_event in target_instance.search():
#                 print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
#                 target_instance.delete_event(target_event['Event']['id'])

#         source_instance.delete_event(event.id)




#class TestAttributePropagation(unittest.TestCase):
    # def testUpdatedAttributeOnPush(self):
    #     """
    #     Test that an updated attribute is correctly propagated to the target instances.
    #     The event should be pushed to the target instances with the updated attribute.
    #     """
    #     # Use the first MISP instance as source
    #     source_instance = misps[0]
        
    #     # Create an event
    #     event = create_event('Event for updated attribute on push')
    #     event.distribution = 2
    #     attribute = event.add_attribute('text', 'initial_value')

    #     #attribute = create_attribute('text', 'initial_value')
    #     #attribute_uuid = attribute.uuid
    #     #attribute.Event = event
        
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid
        
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Get the server configurations linked to this instance
    #     servers = source_instance.servers()
    #     servers_id = get_servers_id(servers)
    #     if not servers_id:
    #         raise Exception("No server configuration found for the source instance")

    #     # Push the event to each linked server (before publication)
    #     for server_id in servers_id:
    #         push_response = source_instance.server_push(server=server_id, event=event.id)
    #         time.sleep(2)  # Allow time for push to complete
    #         check_response(push_response)

    #     # Verify that the event is present on each target instance
    #     linked_server_numbers = extract_server_numbers(servers)
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid)
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after push"
    #         )

    #     # Update an attribute on the source instance
    #     attribute.value = 'updated_value'
    #     updated_attribute = source_instance.update_attribute(attribute, pythonify=True)
    #     check_response(updated_attribute)
    #     #result = source_instance.search(uuid=event_uuid)
    #     #print(f" \n \n Result after update: {result} \n \n")
    #     #updated_event = source_instance.update_event(event, pythonify=True)
    #     #check_response(updated_event)

    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Verify that the updated attribute is present on each target instance
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid)
    #         #print(f" \n \n Search results on MISP_{target_index}: longueur {len(search_results)} \n \n")
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after attribute update"
    #         )
    #         for result in search_results:
    #             attributes = result['Event']['Attribute']
    #             found = False
    #             for attr in attributes:
    #                 #print(f"Checking attribute value: {attr}")
    #                 if attr['value'] == 'updated_value':
    #                     found = True
    #                     break
    #             self.assertTrue(found, f"Updated attribute not found on MISP_{target_index}")

    #     # Cleanup: delete the event on all linked servers and the source
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         for target_event in target_instance.search():
    #             print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
    #             target_instance.delete_event(target_event['Event']['id'])

    #     source_instance.delete_event(event.id)


    # def testUpdatedAttributeOnPull(self):
    #     """
    #     Test that an updated attribute is correctly pulled from the source instance to the target instances.
    #     The event should be pulled from the source instance with the updated attribute.
    #     """
    #     source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
    #     print(f"Unidirectional link: {source_index} --> {target_index}")

    #     # Create an event on the source instance
    #     event_name = f"Event {source_index} for pull on {target_index} with updated attribute"
    #     event = create_event(event_name)
    #     event.distribution = 2
    #     attribute = event.add_attribute('text', 'initial_value')
        
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid

    #     # Publish the event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Perform the pull on the target instance
    #     pull_result = target_instance.server_pull(server=server_id, event=event.id)
    #     time.sleep(2)  # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Confirm the event exists on the target instance
    #     found = False
    #     results = target_instance.search(uuid=uuid)
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_attr = False
    #             for attr in attributes:
    #                 if attr['value'] == 'initial_value':
    #                     found_attr = True
    #                     break
    #             self.assertTrue(found_attr, f"Initial attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

    #     # Update an attribute on the source instance
    #     attribute.value = 'updated_value'
    #     updated_attribute = source_instance.update_attribute(attribute, pythonify=True)
    #     check_response(updated_attribute)

    #     # Publish the updated event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Perform the pull again to get the updated attribute
    #     pull_result = target_instance.server_pull(server=server_id, event=event.id)
    #     time.sleep(2)  # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Confirm the updated attribute exists on the target instance
    #     results = target_instance.search(uuid=uuid)
    #     found = False
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_attr = False
    #             for attr in attributes:
    #                 if attr['value'] == 'updated_value':
    #                     found_attr = True
    #                     break
    #             self.assertTrue(found_attr, f"Updated attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with updated attribute")

    #     # Cleanup: delete events on both instances
    #     for e in target_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
    #         target_instance.delete_event(e['Event']['id'])
    #     for e in source_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
    #         source_instance.delete_event(e['Event']['id'])


    # def testSoftDeleteAttributeOnPush(self):
    #     """
    #     Test that a soft-deleted attribute is correctly propagated to the target instances.
    #     The event should be pushed to the target instances with the soft-deleted attribute.
    #     """
    #     # Use the first MISP instance as source
    #     source_instance = misps[0]
        
    #     # Create an event
    #     event = create_event('Event for soft delete attribute on push')
    #     event.distribution = 2
    #     attribute = event.add_attribute('text', 'Gotta be deleted')
        
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid
        
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Get the server configurations linked to this instance
    #     servers = source_instance.servers()
    #     servers_id = get_servers_id(servers)
    #     if not servers_id:
    #         raise Exception("No server configuration found for the source instance")

    #     # Verify that the event is present on each target instance
    #     linked_server_numbers = extract_server_numbers(servers)
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid)
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after push"
    #         )

    #     # Soft delete the attribute on the source instance
    #     attribute.delete()
    #     updated_attribute = source_instance.update_attribute(attribute, pythonify=True)
    #     check_response(updated_attribute)

    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)

    #     # Verify that the soft-deleted attribute is present on each target instance
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid, deleted=True)
    #         #print(f" \n \n Search results on MISP_{target_index}: {search_results}")
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after attribute soft delete"
    #         )
    #         for result in search_results:
    #             attributes = result['Event']['Attribute']
    #             found = False
    #             for attr in attributes:
    #                 if attr['value'] == 'Gotta be deleted' and attr['deleted'] is True:
    #                     found = True
    #                     break
    #             self.assertTrue(found, f"Soft-deleted attribute not found on MISP_{target_index}")

    #     # Cleanup: delete the event on all linked servers and the source
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         for target_event in target_instance.search():
    #             print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
    #             target_instance.delete_event(target_event['Event']['id'])

    #     source_instance.delete_event(event.id)


    # def testSoftDeleteAttributeOnPull(self):
    #     """
    #     Test that a soft-deleted attribute is correctly pulled from the source instance to the target instances.
    #     The event should be pulled from the source instance with the soft-deleted attribute.
    #     """
    #     source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
    #     print(f"Unidirectional link: {source_index} --> {target_index}")

    #     # Create an event on the source instance
    #     event_name = f"Event {source_index} for pull on {target_index} with soft-deleted attribute"
    #     event = create_event(event_name)
    #     event.distribution = 2
    #     attribute = event.add_attribute('text', 'Gotta be deleted')
        
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid

    #     # Publish the event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation 

    #     # Perform the pull on the target instance
    #     pull_result = target_instance.server_pull(server=server_id, event=event.id)
    #     time.sleep(2)
    #     check_response(pull_result)

    #     # Confirm the event exists on the target instance
    #     found = False
    #     results = target_instance.search(uuid=uuid)
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_attr = False
    #             for attr in attributes:
    #                 if attr['value'] == 'Gotta be deleted':
    #                     found_attr = True
    #                     break
    #             self.assertTrue(found_attr, f"Initial attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

    #     # Soft delete the attribute on the source instance
    #     attribute.delete()
    #     updated_attribute = source_instance.update_attribute(attribute, pythonify=True)
    #     check_response(updated_attribute)

    #     # Publish the updated event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Perform the pull again to get the soft-deleted attribute
    #     pull_result = target_instance.server_pull(server=server_id, event=event.id)
    #     time.sleep(2)  # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Confirm the soft-deleted attribute exists on the target instance
    #     results = target_instance.search(uuid=uuid, deleted=True)
    #     found = False
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_attr = False
    #             for attr in attributes:
    #                 if attr['value'] == 'Gotta be deleted' and attr['deleted'] is True:
    #                     found_attr = True
    #                     break
    #             self.assertTrue(found_attr, f"Soft-deleted attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with soft-deleted attribute")

    #     # Cleanup: delete events on both instances
    #     for e in target_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
    #         target_instance.delete_event(e['Event']['id'])
    #     for e in source_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
    #         source_instance.delete_event(e['Event']['id'])


    # def testUpdatedProposalAttributeOnPush(self):
    #     """
    #     Test that an updated proposal attribute is correctly propagated to the target instances.
    #     The event should be pushed to the target instances with the updated proposal attribute.
    #     """
    #     # Use the first MISP instance as source
    #     source_instance = misps[0]
        
    #     # Create an event
    #     event = create_event('Event for updated proposal attribute on push')
    #     event.distribution = 2
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid

    #     # Add attribute
    #     new_attribute = MISPAttribute()
    #     new_attribute.value = 'John'
    #     new_attribute.type = 'first-name'
    #     new_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)

    #     #Add first proposal
    #     first_new_proposal = MISPAttribute()
    #     first_new_proposal.value = 'Doe'
    #     first_new_proposal.type = 'last-name'
    #     print(f"UUID of the proposal: {first_new_proposal.uuid}")
    #     first_new_proposal = source_instance.add_attribute_proposal(event.id, first_new_proposal)
    #     print(f"UUID of the proposal: {first_new_proposal}")

    #     #Add Second proposal
    #     second_new_proposal = MISPAttribute()
    #     second_new_proposal.value = 'Dope'
    #     second_new_proposal.type = 'last-name'
    #     second_new_proposal = source_instance.add_attribute_proposal(event.id, second_new_proposal)

    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Get the server configurations linked to this instance
    #     servers = source_instance.servers()
    #     servers_id = get_servers_id(servers)
    #     if not servers_id:
    #         raise Exception("No server configuration found for the source instance")
        
    #     # Perform the push all on all linked servers (proposal is not share with a single publication)
    #     for server_id in servers_id:
    #         push_response = source_instance.server_push(server=server_id)
    #         time.sleep(2)  # Allow time for push to complete
    #         check_response(push_response)

    #     # Verify that the event is present on each target instance with the proposal attribute
    #     linked_server_numbers = extract_server_numbers(servers)
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid)
    #         #print(f" \n \n Search results on MISP_{target_index}: {search_results} \n \n")
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after push"
    #         )
    #         for result in search_results:
    #             proposal = result['Event']['ShadowAttribute']
    #             found_proposal = False
    #             for prop in proposal:
    #                 if prop['value'] == 'Doe':
    #                     found_proposal = True
    #                     break
    #             self.assertTrue(found_proposal, f"Proposal attribute not found on MISP_{target_index}")

    #     # # Update: accept the first proposal and reject the second one
    #     # print(f"UUID of the proposal: {first_new_proposal}")
    #     # response = source_instance.accept_attribute_proposal(first_new_proposal)
    #     # print(f"Response after accepting first proposal: {response}")
    #     # self.assertEqual(response['errors'], 'Proposed change accepted.')
    #     # #check_response(response)
    #     # response = source_instance.discard_attribute_proposal(second_new_proposal)
    #     # #check_response(response)

    #     # # Publish the event immediately
    #     # publish_immediately(source_instance, event, with_email=False)
    #     # time.sleep(2)

    #     # # Perform the push all again to get the updated proposals
    #     # for server_id in servers_id:
    #     #     push_response = source_instance.server_push(server=server_id)
    #     #     time.sleep(2)  # Allow time for push to complete
    #     #     check_response(push_response)

    #     # # Verify that the first proposal is present as an attribute on each target instance and the second proposal is not present
    #     # for target_index in linked_server_numbers:
    #     #     target_instance = misps[target_index - 1]
    #     #     search_results = target_instance.search(uuid=uuid)
    #     #     self.assertGreater(
    #     #         len(search_results), 0,
    #     #         f"Event not found on MISP_{target_index} after proposal update"
    #     #     )
    #     #     for result in search_results:
    #     #         attributes = result['Event']['Attribute']
    #     #         found_first_proposal = False
    #     #         found_second_proposal = False
    #     #         for attr in attributes:
    #     #             if attr['value'] == 'Doe':
    #     #                 found_first_proposal = True
    #     #             if attr['value'] == 'Dope':
    #     #                 found_second_proposal = True
    #     #         self.assertTrue(found_first_proposal.id, f"Accepted proposal not found on MISP_{target_index}")
    #     #         self.assertFalse(found_second_proposal, f"Discarded proposal found on MISP_{target_index}")


    #     # Cleanup: delete the event on all linked servers and the source
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         for target_event in target_instance.search():
    #             print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
    #             target_instance.delete_event(target_event['Event']['id'])

    #     source_instance.delete_event(event.id)



    # def testUpdatedProposalAttributeOnPull(self):
    #     """
    #     Test that an updated proposal attribute is correctly pulled from the source instance to the target instances.
    #     The event should be pulled from the source instance with the updated proposal attribute.
    #     """
    #     source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
    #     print(f"Unidirectional link: {source_index} --> {target_index}")

    #     # Create an event on the source instance
    #     event_name = f"Event {source_index} for pull on {target_index} with updated proposal attribute"
    #     event = create_event(event_name)
    #     event.distribution = 2
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid

    #     # Add attribute
    #     new_attribute = MISPAttribute()
    #     new_attribute.value = 'John'
    #     new_attribute.type = 'first-name'
    #     new_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)

    #     #Add first proposal
    #     first_new_proposal = MISPAttribute()
    #     first_new_proposal.value = 'Doe'
    #     first_new_proposal.type = 'last-name'
    #     first_new_proposal = source_instance.add_attribute_proposal(event.id, first_new_proposal)

    #     #Add Second proposal
    #     second_new_proposal = MISPAttribute()
    #     second_new_proposal.value = 'Dope'
    #     second_new_proposal.type = 'last-name'
    #     second_new_proposal = source_instance.add_attribute_proposal(event.id, second_new_proposal)

    #     # Publish the event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Perform the pull on the target instance
    #     pull_result = target_instance.server_pull(server=server_id)
    #     time.sleep(2) # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Confirm the event exists on the target instance with the proposal attribute
    #     found = False
    #     results = target_instance.search(uuid=uuid)
    #     if results:
    #         found = True
    #         for result in results:
    #             proposal = result['Event']['ShadowAttribute']
    #             found_proposal = False
    #             for prop in proposal:
    #                 if prop['value'] == 'Doe':
    #                     found_proposal = True
    #                     break
    #             self.assertTrue(found_proposal, f"Proposal attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

    #     # Update: accept the first proposal and reject the second one
    #     response = source_instance.accept_attribute_proposal(first_new_proposal)
    #     print(f"Response after accepting first proposal: {response}")
    #     self.assertEqual(response['errors'], 'Proposed change accepted.')
    #     #check_response(response)
    #     response = source_instance.discard_attribute_proposal(second_new_proposal)
    #     #check_response(response)

    #     # Publish the event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)

    #     # Perform the pull again to get the updated proposals
    #     pull_result = target_instance.server_pull(server=server_id)
    #     time.sleep(2)  # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Verify that the first proposal is present as an attribute on the target instance and the second proposal is not present
    #     results = target_instance.search(uuid=uuid)
    #     found = False
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_first_proposal = False
    #             found_second_proposal = False
    #             for attr in attributes:
    #                 if attr['value'] == 'Doe':
    #                     found_first_proposal = True
    #                 if attr['value'] == 'Dope':
    #                     found_second_proposal = True
    #             self.assertTrue(found_first_proposal, f"Accepted proposal not found on MISP_{target_index}")
    #             self.assertFalse(found_second_proposal, f"Discarded proposal found on MISP_{target_index}")
                
    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull with updated proposals") 

    #     # Cleanup: delete events on both instances
    #     for e in target_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
    #         target_instance.delete_event(e['Event']['id'])
    #     for e in source_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
    #         source_instance.delete_event(e['Event']['id'])


    # def testDeletedProposalAttributeOnPush(self):
    #     """
    #     Test that a deleted proposal attribute is correctly propagated to the target instances.
    #     The event should be pushed to the target instances with the deleted proposal attribute.
    #     """
    #     # Use the first MISP instance as source
    #     source_instance = misps[0]
        
    #     # Create an event
    #     event = create_event('Event for deleted proposal attribute on push')
    #     event.distribution = 2
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid

    #     # Add attribute
    #     new_attribute = MISPAttribute()
    #     new_attribute.value = 'John'
    #     new_attribute.type = 'first-name'
    #     new_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)

    #     #Propose the soft deletion of the attribute
    #     response = source_instance.delete_attribute_proposal(new_attribute)
    #     print("Response after proposing deletion:", response)

    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Get the server configurations linked to this instance
    #     servers = source_instance.servers()
    #     servers_id = get_servers_id(servers)
    #     if not servers_id:
    #         raise Exception("No server configuration found for the source instance")
        
    #     # Perform the push all on all linked servers (proposal is not share with a single publication)
    #     for server_id in servers_id:
    #         push_response = source_instance.server_push(server=server_id)
    #         time.sleep(2)  # Allow time for push to complete
    #         check_response(push_response)
            
    #     # Verify that the event is present on each target instance with the proposal attribute
    #     linked_server_numbers = extract_server_numbers(servers)
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         search_results = target_instance.search(uuid=uuid)
    #         #print(f" \n \n Search results on MISP_{target_index}: {search_results} \n \n")
    #         self.assertGreater(
    #             len(search_results), 0,
    #             f"Event not found on MISP_{target_index} after push"
    #         )
    #         for result in search_results:
    #             attributes = result['Event']['Attribute']
    #             found_proposal = False
    #             for attr in attributes:
    #                 if attr['value'] == 'John':
    #                     for prop in attr['ShadowAttribute']:
    #                         if prop['proposal_to_delete'] is True:
    #                             found_proposal = True
    #                             break
    #             self.assertTrue(found_proposal, f"Proposal attribute not found on MISP_{target_index}")

    #     # Cleanup: delete the event on all linked servers and the source
    #     for target_index in linked_server_numbers:
    #         target_instance = misps[target_index - 1]
    #         for target_event in target_instance.search():
    #             print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
    #             target_instance.delete_event(target_event['Event']['id'])

    #     source_instance.delete_event(event.id)


    # def testDeletedProposalAttributeOnPull(self):
    #     """
    #     Test that a deleted proposal attribute is correctly pulled from the source instance to the target instances.
    #     The event should be pulled from the source instance with the deleted proposal attribute.
    #     """
    #     source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
    #     print(f"Unidirectional link: {source_index} --> {target_index}")

    #     # Create an event on the source instance
    #     event_name = f"Event {source_index} for pull on {target_index} with deleted proposal attribute"
    #     event = create_event(event_name)
    #     event.distribution = 2
    #     event = source_instance.add_event(event, pythonify=True)
    #     check_response(event)
    #     self.assertIsNotNone(event.id)
    #     uuid = event.uuid
        
    #     # Add attribute
    #     new_attribute = MISPAttribute()
    #     new_attribute.value = 'John'
    #     new_attribute.type = 'first-name'
    #     new_attribute = source_instance.add_attribute(event, new_attribute, pythonify=True)

    #     #Propose the soft deletion of the attribute
    #     response = source_instance.delete_attribute_proposal(new_attribute)
    #     print("Response after proposing deletion:", response)

    #     # Publish the event immediately
    #     publish_immediately(source_instance, event, with_email=False)
    #     time.sleep(2)  # Give time for sync propagation

    #     # Perform the pull on the target instance
    #     pull_result = target_instance.server_pull(server=server_id)
    #     time.sleep(2)  # Allow time for pull to complete
    #     check_response(pull_result)

    #     # Confirm the event exists on the target instance with the proposal attribute
    #     found = False
    #     results = target_instance.search(uuid=uuid)
    #     if results:
    #         found = True
    #         for result in results:
    #             attributes = result['Event']['Attribute']
    #             found_proposal = False
    #             for attr in attributes:
    #                 if attr['value'] == 'John':
    #                     for prop in attr['ShadowAttribute']:
    #                         if prop['proposal_to_delete'] is True:
    #                             found_proposal = True
    #                             break
    #             self.assertTrue(found_proposal, f"Proposal attribute not found on MISP_{target_index}")

    #     self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

    #     # Cleanup: delete events on both instances
    #     for e in target_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
    #         target_instance.delete_event(e['Event']['id'])
    #     for e in source_instance.search():
    #         print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
    #         source_instance.delete_event(e['Event']['id'])





# class TestLocalTagPropagation(unittest.TestCase):
#     def testLocalTagPropagationOnPush(self):
#         """
#         Test that a local tag is NOT propagated to the target instances.
#         The event should be pushed to the target instances without the local tag.
#         """
#         # Use the first MISP instance as source
#         source_instance = misps[0]
        
#         # Create an event
#         event = create_event('Event with a local tag')
#         event.distribution = 2
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid

#         tag = MISPTag()
#         tag.name = 'This is a local tag'
#         tag.local_only = True
#         new_local_tag = source_instance.add_tag(tag, pythonify=True)
#         check_response(new_local_tag)

#         tag = MISPTag()
#         tag.name = 'This is not a local tag'
#         new_global_tag = source_instance.add_tag(tag, pythonify=True)
#         check_response(new_global_tag)
        
#         source_instance.tag(event, new_local_tag.name, local=True) #Need to specify again with flag local
#         source_instance.tag(event, new_global_tag.name)

#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Get the server configurations linked to this instance
#         servers = source_instance.servers()
#         servers_id = get_servers_id(servers)
#         if not servers_id:
#             raise Exception("No server configuration found for the source instance")

#         # Verify that the event is present on all target instances in connected communities 
#         # with a the glonal tag but not the local one
#         linked_server_numbers = extract_server_numbers(servers)
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after push"
#             )
#             for result in search_results:
#                 tags = result['Event']['Tag']
#                 found_local_tag = False
#                 found_global_tag = False
#                 for tag in tags:
#                     if tag["name"] == new_local_tag.name:
#                         found_local_tag = True
#                     if tag["name"]== new_global_tag.name:
#                         found_global_tag = True
#                 self.assertFalse(found_local_tag, f"Local tag found on MISP_{target_index}")
#                 self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

#         # Cleanup: delete the event on all linked servers and the source
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             for target_event in target_instance.search():
#                 print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
#                 target_instance.delete_event(target_event['Event']['id'])

#         source_instance.delete_tag(new_local_tag)
#         source_instance.delete_tag(new_global_tag)
#         source_instance.delete_event(event.id)



#     def testLocalTagPropagationOnPull(self):
#         """
#         Test that a local tag is NOT propagated to the target instances.
#         The event should be pushed to the target instances without the local tag.
#         """
#         source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
#         print(f"Unidirectional link: {source_index} --> {target_index}")

#         # Create an event on the source instance
#         event_name = f"Event {source_index} with a local tag for pull on {target_index}"
#         event = create_event(event_name)
#         event.distribution = 2
        
#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid

#         tag = MISPTag()
#         tag.name = 'This is a local tag'
#         tag.local_only = True
#         new_local_tag = source_instance.add_tag(tag, pythonify=True)
#         check_response(new_local_tag)

#         tag = MISPTag()
#         tag.name = 'This is not a local tag'
#         new_global_tag = source_instance.add_tag(tag, pythonify=True)
#         check_response(new_global_tag)

#         source_instance.tag(event, new_local_tag.name, local=True)  # Need to specify again with flag local
#         source_instance.tag(event, new_global_tag.name)
        
#         # Publish the event immediately
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)  # Give time for sync propagation

#         # Perform the pull on the target instance
#         pull_result = target_instance.server_pull(server=server_id)
#         time.sleep(2)  # Allow time for pull to complete
#         check_response(pull_result)

#         # Confirm the event exists on the target instance without the local tag
#         found = False
#         results = target_instance.search(uuid=uuid)
#         if results:
#             found = True
#             for result in results:
#                 tags = result['Event']['Tag']
#                 found_local_tag = False
#                 found_global_tag = False
#                 for tag in tags:
#                     if tag["name"] == new_local_tag.name:
#                         found_local_tag = True
#                     if tag["name"] == new_global_tag.name:
#                         found_global_tag = True
#                 self.assertFalse(found_local_tag, f"Local tag found on MISP_{target_index}")
#                 self.assertTrue(found_global_tag, f"Global tag not found on MISP_{target_index}")

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

#         # Cleanup: delete events on both instances
#         for e in target_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
#             target_instance.delete_event(e['Event']['id'])
#         for e in source_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
#             source_instance.delete_event(e['Event']['id'])




# class TestUpdatedEvent(unittest.TestCase):
#     def testUpdatedEventnOnPush(self):
#         """
#         Test that an updated event is correctly propagated to the target instances.
#         """
#         # Use the first MISP instance
#         source_instance = misps[0]

#         # Create an event
#         event = create_event('Event before update')
#         event.distribution = 2

#         event = source_instance.add_event(event, pythonify=True)
#         uuid = event.uuid
#         check_response(event)
#         self.assertIsNotNone(event.id)

#         # Publish the event immediately (without sending email)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)

#         # Get the server configurations linked to this instance
#         servers = source_instance.servers()
#         servers_id = get_servers_id(servers)
#         if not servers_id:
#             raise Exception("No server configuration found for the source instance")

#         # Verify that the event is present on the targets with the info 'Event before update'
#         linked_server_numbers = extract_server_numbers(servers)
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after publication"
#             )
#             for result in search_results:
#                 self.assertEqual(
#                     result['Event']['info'], 'Event before update',
#                     f"Event info mismatch on MISP_{target_index}"
#                 )

#         # Update the event on the source instance
#         event.info = 'Event after update'
#         updated_event = source_instance.update_event(event, pythonify=True)
#         check_response(updated_event)

#         # Publish the updated event immediately (without sending email)
#         publish_immediately(source_instance, updated_event, with_email=False)
#         time.sleep(2)

#         # Push the event on each linked server (kind of useless but for consistency)
#         for server_id in servers_id:
#             push_response = source_instance.server_push(server=server_id, event=updated_event.id)
#             time.sleep(2)  # Allow time for push to complete
#             check_response(push_response)

#         # Confirm the event now exists on each linked server with the updated info
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             search_results = target_instance.search(uuid=uuid)
#             self.assertGreater(
#                 len(search_results), 0,
#                 f"Event not found on MISP_{target_index} after publication"
#             )
#             for result in search_results:
#                 self.assertEqual(
#                     result['Event']['info'], 'Event after update',
#                     f"Updated event info mismatch on MISP_{target_index}"
#                 )

#         # Cleanup: delete the event on all linked servers and the source
#         for target_index in linked_server_numbers:
#             target_instance = misps[target_index - 1]
#             for target_event in target_instance.search():
#                 print(f"Deleting Event {target_event['Event']['id']} on MISP_{target_index}")
#                 target_instance.delete_event(target_event['Event']['id'])

#         source_instance.delete_event(event.id)


#     def testUpdatedEventOnPull(self):
#         """
#         Test that an updated event is correctly pulled from the source instance to the target instances.
#         """
#         source_instance, target_instance, source_index, target_index, server_id = find_unidirectional_link()
#         print(f"Unidirectional link: {source_index} --> {target_index}")

#         # Create an event on the source instance
#         event_name = f"Event {source_index} for pull on {target_index}"
#         event = create_event(event_name)
#         event.distribution = 2

#         event = source_instance.add_event(event, pythonify=True)
#         check_response(event)
#         self.assertIsNotNone(event.id)
#         uuid = event.uuid

#         # Publish the event immediately (without sending email)
#         publish_immediately(source_instance, event, with_email=False)
#         time.sleep(2)

#         # Perform the pull on the target instance
#         pull_result = target_instance.server_pull(server=server_id, event=event.id)
#         time.sleep(2)  # Allow time for pull to complete
#         check_response(pull_result)

#         # Confirm the event exists on the target instance
#         found = False
#         results = target_instance.search(uuid=uuid)

#         if results:
#             found = True
#             for result in results:
#                 self.assertEqual(
#                     result['Event']['info'], event_name,
#                     f"Event info mismatch on MISP_{target_index}"
#                 )

#         self.assertTrue(found, f"Event not found on MISP_{target_index} after pull")

#         # Update the event on the source instance
#         event.info = 'Updated Event after pull'
#         updated_event = source_instance.update_event(event, pythonify=True)
#         check_response(updated_event)

#         # Publish the updated event immediately (without sending email)
#         publish_immediately(source_instance, updated_event, with_email=False)
#         time.sleep(2)

#         # Perform the pull again to get the updated event
#         pull_result = target_instance.server_pull(server=server_id, event=updated_event.id)
#         time.sleep(2)  # Allow time for pull to complete
#         check_response(pull_result)

#         # Confirm the updated event exists on the target instance
#         results = target_instance.search(uuid=uuid)
#         found = False

#         if results:
#             found = True
#             for result in results:
#                 self.assertEqual(
#                     result['Event']['info'], 'Updated Event after pull',
#                     f"Event info mismatch on MISP_{target_index}"
#                 )

#         self.assertTrue(found, f"Updated event not found on MISP_{target_index} after pull")

#         for e in target_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{target_index}")
#             target_instance.delete_event(e['Event']['id'])
#         for e in source_instance.search():
#             print(f"Deleting Event {e['Event']['id']} on MISP_{source_index}")
#             source_instance.delete_event(e['Event']['id'])



if __name__ == '__main__':
    unittest.main()
