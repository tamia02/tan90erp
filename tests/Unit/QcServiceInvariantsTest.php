<?php

namespace Tests\Unit;

use App\Services\QcService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QcServiceInvariantsTest extends TestCase
{
    public function test_accepted_only_line_can_pass_without_evidence(): void
    {
        $physical = app(QcService::class)->validateQcInput(
            ['accepted' => 8, 'qcHold' => 0, 'defective' => 0, 'rejected' => 0],
            8,
            1200,
            150,
            [],
        );

        $this->assertSame(8, $physical);
    }

    public function test_held_line_requires_a_comment_but_not_images(): void
    {
        $physical = app(QcService::class)->validateQcInput(
            ['accepted' => 7, 'qcHold' => 1, 'defective' => 0, 'rejected' => 0],
            8,
            1200,
            150,
            [],
            'Awaiting specification confirmation',
        );

        $this->assertSame(8, $physical);
    }

    public function test_held_line_without_comment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(QcService::class)->validateQcInput(
            ['accepted' => 7, 'qcHold' => 1, 'defective' => 0, 'rejected' => 0],
            8,
            1200,
            150,
            [],
        );
    }

    public function test_disposition_quantities_must_equal_physical_quantity(): void
    {
        $this->expectException(ValidationException::class);

        app(QcService::class)->validateQcInput(
            ['accepted' => 6, 'qcHold' => 0, 'defective' => 1, 'rejected' => 0],
            8,
            1200,
            150,
            [],
        );
    }

    public function test_defective_and_rejected_lines_accept_two_image_classifications(): void
    {
        $physical = app(QcService::class)->validateQcInput(
            ['accepted' => 3, 'qcHold' => 0, 'defective' => 1, 'rejected' => 1],
            5,
            1200,
            240,
            [
                ['path' => 'qc/defect.jpg', 'type' => 'Defective Image'],
                ['path' => 'qc/rejected.jpg', 'type' => 'Rejected Image'],
            ],
        );

        $this->assertSame(5, $physical);
    }

    public function test_rejected_quantity_requires_a_rejected_tagged_image(): void
    {
        $this->expectException(ValidationException::class);

        app(QcService::class)->validateQcInput(
            ['accepted' => 4, 'qcHold' => 0, 'defective' => 0, 'rejected' => 2],
            6,
            100,
            10,
            [
                ['path' => 'qc/defect-a.jpg', 'type' => 'Defective Image'],
                ['path' => 'qc/defect-b.jpg', 'type' => 'Defective Image'],
            ],
        );
    }

    public function test_blank_optional_quality_buckets_are_treated_as_zero(): void
    {
        $physical = app(QcService::class)->validateQcInput(
            ['accepted' => 6, 'qcHold' => '', 'defective' => null, 'rejected' => ''],
            6,
            100,
            10,
            [],
        );

        $this->assertSame(6, $physical);
    }

    public function test_all_three_required_qc_parameters_must_have_values(): void
    {
        $this->expectException(ValidationException::class);

        app(QcService::class)->validateRequiredParameters([
            ['name' => 'Quantity', 'value' => '8'],
            ['name' => 'Invoice / PO Amount', 'value' => '1200'],
            ['name' => 'Unit Price', 'value' => ''],
        ]);
    }
}
