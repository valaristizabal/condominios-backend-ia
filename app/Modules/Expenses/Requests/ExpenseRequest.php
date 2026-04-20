<?php

namespace App\Modules\Expenses\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $requiredRule = $isUpdate ? ['sometimes', 'required'] : ['required'];

        return [
            'registeredAt' => array_merge($requiredRule, ['date', 'before_or_equal:today']),
            'expenseType' => array_merge($requiredRule, [
                'string',
                Rule::in(['servicios', 'mantenimiento', 'honorarios', 'papeleria', 'seguridad', 'aseo']),
            ]),
            'amount' => array_merge($requiredRule, ['numeric', 'gt:0', 'lt:1000000000']),
            'paymentMethod' => array_merge($requiredRule, [
                'string',
                Rule::in(['transferencia', 'efectivo', 'debito', 'tarjeta', 'consignacion']),
            ]),
            'registeredBy' => array_merge($requiredRule, ['string', 'min:3', 'max:255']),
            'observations' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'string', 'max:500'],
            'support' => [$isUpdate ? 'sometimes' : 'nullable', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:2048'],
            'removeSupport' => [$isUpdate ? 'sometimes' : 'nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'registeredAt.required' => 'La fecha de registro es obligatoria.',
            'registeredAt.date' => 'La fecha de registro debe tener un formato valido.',
            'registeredAt.before_or_equal' => 'La fecha de registro no puede ser futura.',

            'expenseType.required' => 'El tipo de gasto es obligatorio.',
            'expenseType.string' => 'El tipo de gasto debe ser texto.',
            'expenseType.in' => 'El tipo de gasto no es valido.',

            'amount.required' => 'El valor del gasto es obligatorio.',
            'amount.numeric' => 'El valor del gasto debe ser numerico.',
            'amount.gt' => 'El valor del gasto debe ser mayor a 0.',
            'amount.lt' => 'El valor del gasto supera el maximo permitido.',

            'paymentMethod.required' => 'El medio de pago es obligatorio.',
            'paymentMethod.string' => 'El medio de pago debe ser texto.',
            'paymentMethod.in' => 'El medio de pago no es valido.',

            'registeredBy.required' => 'El responsable de registro es obligatorio.',
            'registeredBy.string' => 'El responsable de registro debe ser texto.',
            'registeredBy.min' => 'El responsable de registro debe tener al menos 3 caracteres.',
            'registeredBy.max' => 'El responsable de registro no puede superar 255 caracteres.',

            'observations.string' => 'Las observaciones deben ser texto.',
            'observations.max' => 'Las observaciones no pueden superar 500 caracteres.',

            'support.file' => 'El soporte debe ser un archivo valido.',
            'support.mimes' => 'El soporte debe ser un archivo PDF, JPG, JPEG o PNG.',
            'support.max' => 'El soporte no puede superar 2MB.',

            'removeSupport.boolean' => 'El indicador para eliminar soporte debe ser booleano.',
        ];
    }
}
