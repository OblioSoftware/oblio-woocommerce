<?php

if (!defined('ABSPATH')) {
    exit;
}

class Oblio_Autocomplete {

    /**
     * Mapare field => key wp_option
     * ex: 'issuerName' => 'oblio_autocomplete_issuerName'
     *
     * @var array<string,string>
     */
    public static $fields = array(
        'issuerName'         => 'oblio_autocomplete_issuerName',
        'issuerId'           => 'oblio_autocomplete_issuerId',
        'deputyName'         => 'oblio_autocomplete_deputyName',
        'deputyIdentityCard' => 'oblio_autocomplete_deputyIdentityCard',
        'deputyAuto'         => 'oblio_autocomplete_deputyAuto',
    );

    /**
     * Stochează setul complet folosit la ultima emitere reușită.
     * Array format: field => value
     *
     * @var string
     */
    public static $lastSetOptionKey = 'oblio_autocomplete_lastSet';

    /**
     * Returnează lista de valori pentru un câmp.
     *
     * @param string $field
     * @return array
     */
    public static function get_values($field) {
        if (!isset(self::$fields[$field])) {
            return array();
        }
        $option = self::$fields[$field];
        $values = get_option($option, array());
        if (!is_array($values)) {
            $values = array();
        }
        // Normalize: string keys, unice, ordonate simplu
        $clean = array();
        foreach ($values as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $clean[$v] = $v;
        }
        return array_values($clean);
    }

    /**
     * Adaugă o valoare nouă pentru un câmp (unic, curățat).
     *
     * @param string $field
     * @param string $value
     * @return void
     */
    public static function save_value($field, $value) {
        if (!isset(self::$fields[$field])) {
            return;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return;
        }

        $option = self::$fields[$field];
        $values = get_option($option, array());
        if (!is_array($values)) {
            $values = array();
        }

        // Păstrăm unicitatea, dar actualizăm "recency":
        // dacă valoarea există deja, o scoatem și o re-adăugăm la final.
        $existingIndex = array_search($value, $values, true);
        if ($existingIndex !== false) {
            array_splice($values, $existingIndex, 1);
        }
        $values[] = $value; // ultima valoare = cea mai recent folosită
        update_option($option, $values);
    }

    /**
     * Procesează toate câmpurile dintr-un submit de form.
     *
     * @param array $form_data tipic $_POST sau $_REQUEST
     * @return void
     */
    public static function save_values_from_form(array $form_data) {
        $lastSet = [];
        foreach (self::$fields as $field => $option) {
            if (!isset($form_data[$field])) {
                continue;
            }
            $raw = $form_data[$field];

            if (is_array($raw)) {
                foreach ($raw as $value) {
                    $clean = sanitize_text_field(wp_unslash($value));
                    if (trim((string) $clean) !== '') {
                        $lastSet[$field] = $clean; // ultima valoare ne-goală din listă
                    }
                    self::save_value($field, $clean);
                }
            } else {
                $clean = sanitize_text_field(wp_unslash($raw));
                if (trim((string) $clean) !== '') {
                    $lastSet[$field] = $clean;
                }
                self::save_value($field, $clean);
            }
        }

        if (!empty($lastSet)) {
            update_option(self::$lastSetOptionKey, $lastSet);
        }
    }

    /**
     * Returnează toate câmpurile cu valorile lor.
     *
     * @return array<string,array>
     */
    public static function get_all_values() {
        $result = array();
        foreach (self::$fields as $field => $option) {
            $result[$field] = self::get_values($field);
        }
        return $result;
    }

    /**
     * @return array<string,string> field => value
     */
    public static function get_last_set() {
        $values = get_option(self::$lastSetOptionKey, []);
        if (!is_array($values)) {
            return [];
        }
        $clean = [];
        foreach (self::$fields as $field => $optionKey) {
            if (!isset($values[$field])) {
                continue;
            }
            $v = trim((string) $values[$field]);
            if ($v === '') {
                continue;
            }
            $clean[$field] = $v;
        }
        return $clean;
    }
}