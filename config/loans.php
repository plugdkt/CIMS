<?php

/*
 | ITM-2610-051 (borrow / return module): tunable values, kept out of the code (BR-L06).
 | Step 2 uses the loan period limits, the advisor link lifetime and the student-step switch.
 */
return [
    // BR-L06: standard period offered on the request form, and the longest a loan may run (calendar days).
    // `loan_assets.max_loan_days`, when set, can only SHORTEN the limit for that asset.
    'default_loan_days' => 7,
    'max_loan_days' => 30,

    // A request may ask for pick-up no more than this many days ahead.
    'max_lead_days' => 30,

    // At most this many different assets on one request.
    'max_lines' => 20,

    // FR-LN-12: lifetime of the advisor's emailed approval link (hours), same as the requisition link.
    'advisor_link_hours' => 72,

    /*
     | CEO DECIDED (2026-11-05): after the advisor approves a STUDENT's loan, no scientist / warehouse manager step.
     |   false (current, like the student requisition, option "ก."): the advisor's approval makes it APPROVED at once.
     |   true  (old spec BR-L03): SUBMITTED -> ADVISOR_APPROVED -> (manager) -> APPROVED
     | This is the ONLY place the choice is read (LoanState::studentNeedsManagerStep()); the single test that pins
     | it is LoanStateTest, "student second step switch". Old rows already ADVISOR_APPROVED can still be decided.
     */
    'student_needs_manager_step' => false,

    /*
     | Step 3 (hand-out / return / renew).
     | BR-L07: one renewal moves the due date by at most `default_loan_days`; the first renewal of a loan is approved
     | at once, later ones wait for an approver of the branch. A loan can be renewed at most `max_renewals` times and
     | may never run longer than `max_total_days` counted from the day of hand-out (or twice the shortest
     | `loan_assets.max_loan_days` on the document, when that is less).
     */
    'max_renewals' => 3,
    'max_total_days' => 60,
    // How many times the due date of ONE loan may be ASKED to move, counting rejected requests as well (a rejection
    // does not use up `max_renewals`, so this is what stops endless re-asking). Never above 255 (`seq` is a TINYINT).
    'max_renewal_requests' => 6,

    // BR-L08: a borrower with a loan overdue by MORE than this many days cannot make or submit a new request
    // (until it is returned, or an approver of the branch allows this one request and says why; audited).
    'overdue_block_days' => 7,

    /*
     | Step 5 (the daily notices, `loans:notify-daily`, and the reports).
     | N4: an approved loan not picked up MORE than this many days after its pick-up date is a reminder (first day
     |     past it, then every 7th day). Nothing is cancelled automatically.
     | N5: the borrower is reminded this many days before the due date, and on the due date itself.
     | KPI report: "status unknown" = overdue for more than this many days with nothing recorded on the loan since.
     */
    'pickup_reminder_days' => 3,
    'due_reminder_days' => 2,
    'unknown_after_days' => 30,
];
