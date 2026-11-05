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
];
