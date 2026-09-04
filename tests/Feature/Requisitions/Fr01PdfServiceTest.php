<?php

use App\Domain\Reporting\Services\Fr01PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('Fr01PdfService renders a non-empty PDF for a DRAFT requisition with no lines yet', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);

    $pdf = app(Fr01PdfService::class)->render($requisition);

    expect($pdf)->toStartWith('%PDF');
});

test('Fr01PdfService renders a requisition with lines, an advisor decision, and an issue', function () {
    $student = studentUser();
    $requisition = submittedRequisition($student);
    $advisor = $requisition->advisor;
    app(App\Domain\Requisition\Services\ApprovalService::class)->advisorDecide($requisition, $advisor, 'APPROVE');

    $pdf = app(Fr01PdfService::class)->render($requisition->fresh());

    expect($pdf)->toStartWith('%PDF');
});

test('a user without permission to view the requisition gets 403 on the PDF route', function () {
    $studentA = studentUser();
    $studentB = studentUser();
    $requisition = makeRequisition($studentA);

    $this->actingAs($studentB)->get(route('requisitions.pdf', $requisition))->assertStatus(403);
});

test('the requester can download their own requisition as a PDF', function () {
    $student = studentUser();
    $requisition = makeRequisition($student);

    $response = $this->actingAs($student)->get(route('requisitions.pdf', $requisition));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});
