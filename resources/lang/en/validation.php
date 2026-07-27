<?php

return [
    'required' => 'The :attribute field is required.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'present' => 'The :attribute field must be present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'string'   => 'The :attribute must be a string.',
    'min'      => [
        'string' => 'The :attribute must be at least :min characters.',
    ],
    'max'      => [
        'string' => 'The :attribute may not be greater than :max characters.',
    ],
    'email'     => 'The :attribute must be a valid email address.',
    'unique'    => 'The :attribute has already been taken.',
    'confirmed' => 'The :attribute confirmation does not match.',
    'date'      => 'The :attribute is not a valid date.',
    'date_format' => 'The :attribute must match the format :format.',
    'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
    'integer'   => 'The :attribute must be an integer.',
    'numeric'   => 'The :attribute must be a number.',
    'boolean'   => 'The :attribute must be true or false.',
    'exists'    => 'The selected :attribute is invalid.',
    'in'        => 'The selected :attribute is invalid. It must be one of the following: :values.',
    'regex'     => 'The :attribute format is invalid.',
];
