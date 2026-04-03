# TICKET-003: Add Payment Confirmation Notification with Status Gates

## Problem
When a payment is recorded, we need to send a confirmation email to the debtor. But this notification must respect status gates: only sequences in `active` or `installment` status should receive it.

## Requirements
- Create a new `PaymentConfirmationNotification` Mailable
- Create a `SendPaymentConfirmation` Job that:
  1. Accepts a payment_id
  2. Loads the related sequence
  3. Checks the status gate (only `active` or `installment`)
  4. Sends the email if gate passes, logs skip reason if not
- Wire it: when a UserPayment is created with status `completed`, dispatch the job
- Write a contract test: payment on cancelled sequence does NOT send email
- Write a contract test: payment on active sequence DOES send email
- Write a test: job logs skip reason when gate blocks

## Files to Create
- `app/Jobs/Notifications/SendPaymentConfirmation.php`
- `app/Mail/PaymentConfirmationMail.php`
- `tests/Unit/Flows/PaymentConfirmationFlowTest.php`

## Files to Modify
- `app/Modules/Payment/Observers/UserPaymentObserver.php`

## Hints
- Follow the existing pattern in `app/Jobs/Notifications/` for job structure
- Use `Log::info()` with context for skip reasons
- The contract test pattern: fake the queue, create the model, assert job was/wasn't dispatched
