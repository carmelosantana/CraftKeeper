<?php

namespace Database\Factories;

use App\Models\Operation;
use App\Operations\OperationActorType;
use App\Operations\OperationRisk;
use App\Operations\OperationStatus;
use App\Operations\OperationType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Operation>
 */
class OperationFactory extends Factory
{
    protected $model = Operation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => OperationType::ConfigApply,
            'status' => OperationStatus::Proposed,
            'target' => 'server.properties',
            'risk' => OperationRisk::Standard,
            'author_type' => OperationActorType::Human,
            'author_id' => '1',
            'author_origin' => 'web',
            'redacted_input' => [],
            'correlation_id' => (string) Str::uuid(),
        ];
    }

    public function status(OperationStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function ofType(OperationType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function authoredBy(OperationActorType $type, ?string $id = null, string $origin = 'test'): static
    {
        return $this->state([
            'author_type' => $type,
            'author_id' => $id,
            'author_origin' => $origin,
        ]);
    }
}
