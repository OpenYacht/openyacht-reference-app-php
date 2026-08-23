<?php

namespace App\Http\Requests;

use App\Models\SaleYacht;

class UpdateYachtRequest extends StoreYachtRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $yacht = $this->route('yacht');

        assert($yacht instanceof SaleYacht);

        return $this->user()->can('update', $yacht);
    }
}
