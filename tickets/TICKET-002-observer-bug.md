# TICKET-002: Fix Notification Sent to Cancelled Sequences

## Problem
A bug was reported: sequences with status `cancelled` are still receiving email notifications. The SequenceObserver dispatches a NotifySequenceUpdate job whenever a sequence is updated, but it doesn't check if the sequence is in a terminal status.

## Root Cause
`app/Modules/Sequence/Observers/SequenceObserver.php` dispatches the job on every `updated` event without checking status.

## Requirements
- Modify SequenceObserver to skip notification dispatch for terminal statuses (`cancelled`, `recovered`)
- Add a guard in the NotifySequenceUpdate job's `handle()` method as a safety net
- Write a Unit test proving cancelled sequences don't trigger notifications
- Write a Unit test proving active sequences still DO trigger notifications
- Document the invariant: "Terminal sequences never receive notifications"

## Files to Modify
- `app/Modules/Sequence/Observers/SequenceObserver.php`
- `app/Jobs/Notifications/NotifySequenceUpdate.php`
- Create: `tests/Unit/Flows/SequenceNotificationFlowTest.php`

## Hints
- Terminal statuses: `cancelled`, `recovered`
- Active statuses: `active`, `installment`, `partially_paid_recovery`, `will_pay_later`
- Read the observer pattern before modifying — understand the full chain
