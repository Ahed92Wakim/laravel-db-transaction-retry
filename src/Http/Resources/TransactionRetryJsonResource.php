<?php

namespace DatabaseTransactions\RetryHelper\Http\Resources;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

abstract class TransactionRetryJsonResource extends JsonResource
{
    public static $wrap = 'data';

    public function __construct(mixed $resource, private readonly array $meta = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): mixed
    {
        return $this->normalizeValue($this->resource, 'data');
    }

    public function with(Request $request): array
    {
        if ($this->meta === []) {
            return [];
        }

        return [
            'meta' => $this->normalizeValue($this->meta, 'meta'),
        ];
    }

    private function normalizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if ($value instanceof Model) {
            return $this->normalizeModel($value);
        }

        if ($value instanceof Collection) {
            return $value
                ->map(fn (mixed $item, mixed $itemKey): mixed => $this->normalizeValue(
                    $item,
                    is_string($itemKey) ? $itemKey : null
                ))
                ->all();
        }

        if ($value instanceof Arrayable) {
            return $this->normalizeValue($value->toArray(), $key);
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $itemKey => $itemValue) {
                $normalized[$itemKey] = $this->normalizeValue(
                    $itemValue,
                    is_string($itemKey) ? $itemKey : null
                );
            }

            return $normalized;
        }

        if (is_object($value)) {
            return $this->normalizeValue(get_object_vars($value), $key);
        }

        if (is_string($value) && $this->shouldFormatDateValue($key) && $this->looksLikeDateTime($value)) {
            try {
                return Carbon::parse($value)->toIso8601String();
            } catch (Throwable) {
                return $value;
            }
        }

        return $value;
    }

    private function normalizeModel(Model $model): array
    {
        $attributes = [];

        foreach (array_keys($model->getAttributes()) as $key) {
            $attributes[$key] = $this->normalizeValue($model->getAttribute($key), $key);
        }

        return $attributes;
    }

    private function shouldFormatDateValue(?string $key): bool
    {
        if ($key === null) {
            return false;
        }

        return in_array($key, ['from', 'to', 'timestamp', 'last_seen'], true)
            || str_ends_with($key, '_at')
            || str_ends_with($key, '_seen');
    }

    private function looksLikeDateTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?$/', $value) === 1;
    }
}
