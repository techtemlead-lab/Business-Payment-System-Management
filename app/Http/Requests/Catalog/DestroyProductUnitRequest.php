<?php

namespace App\Http\Requests\Catalog;

use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

class DestroyProductUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('catalog') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ReasonFields::rule('product.taxonomy_remove', 1000, 5),
        ];
    }
}
