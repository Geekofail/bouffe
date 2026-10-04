<?php

namespace App\Livewire\Forms;

use App\Enums\UnitType;
use App\Models\Unit;
use App\Services\QuantityParser;
use Illuminate\Validation\Rule;
use Livewire\Form;

class UnitForm extends Form
{
    public ?Unit $unit = null;

    public string $code = '';

    public string $label = '';

    public string $label_plural = '';

    public string $type = 'piece';

    public string $factor_to_base = '';

    public bool $is_metric = false;

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
        $this->code = $unit->code;
        $this->label = $unit->label;
        $this->label_plural = (string) $unit->label_plural;
        $this->type = $unit->type->value;
        $this->factor_to_base = $unit->factor_to_base !== null
            ? rtrim(rtrim(str_replace('.', ',', (string) $unit->factor_to_base), '0'), ',')
            : '';
        $this->is_metric = $unit->is_metric;
    }

    public function isProtected(): bool
    {
        return $this->unit !== null && in_array($this->unit->code, \App\Livewire\Settings\Units::PROTECTED_CODES, true);
    }

    protected function rules(): array
    {
        $convertible = UnitType::tryFrom($this->type)?->isConvertible() ?? false;

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[a-z0-9_-]+$/', Rule::unique('units', 'code')->ignore($this->unit?->id)],
            'label' => ['required', 'string', 'max:50'],
            'label_plural' => ['nullable', 'string', 'max:50'],
            'type' => ['required', Rule::enum(UnitType::class)],
            'factor_to_base' => [$convertible ? 'required' : 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                $parsed = app(QuantityParser::class)->tryParse($value);
                if ($parsed === false || $parsed === 0.0) {
                    $fail('Le facteur doit être un nombre positif (ex. 1000 ou 15).');
                }
            }],
            'is_metric' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.regex' => 'Le code ne peut contenir que des minuscules sans accent, chiffres, - et _.',
            'factor_to_base.required' => 'Indiquez à combien de g ou ml correspond cette unité.',
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'code' => 'code',
            'label' => 'libellé',
            'label_plural' => 'pluriel',
            'type' => 'type',
            'factor_to_base' => 'facteur',
        ];
    }

    public function save(): Unit
    {
        $this->validate();

        $unit = $this->unit ?? new Unit;

        // Les unités de référence (g, ml, pièce) gardent leur code, leur famille et leur facteur.
        if ($this->isProtected()) {
            $this->code = $unit->code;
            $this->type = $unit->type->value;
            $this->factor_to_base = (string) $unit->factor_to_base;
        }

        $type = UnitType::from($this->type);

        $unit->fill([
            'code' => $this->code,
            'label' => trim($this->label),
            'label_plural' => trim($this->label_plural) ?: null,
            'type' => $type,
            'factor_to_base' => $type->isConvertible() ? app(QuantityParser::class)->parse($this->factor_to_base) : null,
            'is_metric' => $type->isConvertible() && $this->is_metric,
        ])->save();

        return $unit;
    }
}
