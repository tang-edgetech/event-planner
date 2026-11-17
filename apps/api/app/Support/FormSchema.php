<?php

namespace App\Support;

use App\Models\Event;
use Illuminate\Support\Str;

/**
 * The registration form builder: field types and normalising the fields saved on an event.
 */
class FormSchema
{
    /** @var array<string, string> */
    public const TYPES = [
        'salutation' => 'Salutation',
        'text' => 'Text',
        'textarea' => 'Paragraph',
        'email' => 'Email',
        'phone' => 'Phone',
        'nric' => 'NRIC / ID',
        'number' => 'Number',
        'date' => 'Date',
        'select' => 'Dropdown',
        'radio' => 'Single choice',
        'checkboxes' => 'Multiple choice',
        'country' => 'Country',
        'consent' => 'Consent checkbox',
        'hidden' => 'Hidden value',
        'heading' => 'Heading',
        'html' => 'Text block',
    ];

    /** Display-only types: they collect no data. */
    public const LAYOUT_TYPES = ['heading', 'html'];

    public const DEFAULT_SALUTATIONS = ['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Prof.', 'Datuk', 'Dato\'', 'Datin', 'Tan Sri', 'Puan Sri'];

    /**
     * Clean the raw builder rows into a predictable list with unique keys.
     *
     * @param  array<int, mixed>|null  $rows
     * @return list<array<string, mixed>>
     */
    public static function normalise(?array $rows): array
    {
        $fields = [];
        $used = [];
        foreach (array_values($rows ?? []) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = array_key_exists($row['type'] ?? '', self::TYPES) ? $row['type'] : 'text';
            $label = trim((string) ($row['label'] ?? ''));
            $name = Str::slug((string) ($row['name'] ?? ''), '_');
            if ($name === '') {
                $name = Str::slug($label, '_') ?: $type.'_'.($i + 1);
            }
            if ($type === 'salutation' && trim((string) ($row['name'] ?? '')) === '') {
                $name = 'salutation';
            }
            $base = $name;
            $n = 2;
            while (isset($used[$name])) {
                $name = $base.'_'.$n++;
            }
            $used[$name] = true;

            $options = [];
            if (in_array($type, ['select', 'radio', 'checkboxes'], true)) {
                if (($row['options_source'] ?? 'custom') === 'preset') {
                    $options = self::presetOptions((string) ($row['preset'] ?? ''));
                } else {
                    $options = self::lines($row['options'] ?? []);
                }
            } elseif ($type === 'salutation') {
                $options = self::presetOptions('salutation') ?: self::DEFAULT_SALUTATIONS;
            } elseif ($type === 'country') {
                $options = Countries::all();
            }

            $condition = null;
            if (! empty($row['conditional']) && ! empty($row['cond_field'])) {
                $condition = [
                    'field' => Str::slug((string) $row['cond_field'], '_'),
                    'operator' => in_array($row['cond_operator'] ?? 'is', ['is', 'is_not', 'contains', 'not_empty', 'empty'], true) ? ($row['cond_operator'] ?? 'is') : 'is',
                    'value' => (string) ($row['cond_value'] ?? ''),
                ];
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'label' => $label,
                'placeholder' => (string) ($row['placeholder'] ?? ''),
                'help' => (string) ($row['help'] ?? ''),
                'required' => (bool) ($row['required'] ?? false),
                'width' => in_array((string) ($row['width'] ?? '100'), ['100', '66', '50', '33', '25'], true) ? (string) ($row['width'] ?? '100') : '100',
                'options' => $options,
                'options_source' => ($row['options_source'] ?? 'custom') === 'preset' ? 'preset' : 'custom',
                'preset' => (string) ($row['preset'] ?? ''),
                'allow_other' => (bool) ($row['allow_other'] ?? false),
                'format' => $type === 'phone' ? (in_array($row['phone_format'] ?? 'my', ['my', 'intl', 'any'], true) ? ($row['phone_format'] ?? 'my') : 'my')
                    : ($type === 'nric' ? (in_array($row['nric_format'] ?? 'my', ['my', 'my_or_passport', 'any'], true) ? ($row['nric_format'] ?? 'my') : 'my') : ''),
                'unique' => (bool) ($row['unique'] ?? false),
                'default' => (string) ($row['default'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'condition' => $condition,
            ];
        }

        return $fields;
    }

    /**
     * Fields as stored on the event (re-normalised so preset lists stay current).
     *
     * @return list<array<string, mixed>>
     */
    public static function forEvent(Event $event): array
    {
        return self::normalise(self::toBuilderRows($event->form_fields ?? []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dataFields(Event $event): array
    {
        return array_values(array_filter(self::forEvent($event), fn ($f) => ! in_array($f['type'], self::LAYOUT_TYPES, true)));
    }

    /**
     * Stored fields back into builder rows (stored rows already have builder keys).
     *
     * @param  array<int, mixed>  $stored
     * @return array<int, mixed>
     */
    public static function toBuilderRows(array $stored): array
    {
        return array_map(function ($f) {
            if (! is_array($f)) {
                return $f;
            }
            if (isset($f['condition']) && is_array($f['condition'])) {
                $f['conditional'] = true;
                $f['cond_field'] = $f['condition']['field'] ?? '';
                $f['cond_operator'] = $f['condition']['operator'] ?? 'is';
                $f['cond_value'] = $f['condition']['value'] ?? '';
            }
            if (($f['type'] ?? '') === 'phone' && ! isset($f['phone_format'])) {
                $f['phone_format'] = $f['format'] ?? 'my';
            }
            if (($f['type'] ?? '') === 'nric' && ! isset($f['nric_format'])) {
                $f['nric_format'] = $f['format'] ?? 'my';
            }

            return $f;
        }, $stored);
    }

    /**
     * @return list<string>
     */
    public static function presetOptions(string $slug): array
    {
        foreach ((array) setting('extra', 'option_lists', []) as $list) {
            if (($list['slug'] ?? '') === $slug) {
                return array_values($list['options'] ?? []);
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        $lines = is_array($value) ? $value : preg_split('/\r\n|\r|\n/', (string) $value);

        return array_values(array_filter(array_map(fn ($l) => trim((string) $l), $lines ?: []), 'strlen'));
    }
}
