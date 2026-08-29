<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
	public function authorize(): bool
	{
		return $this->user() !== null;
	}

	public function rules(): array
	{
		return [
			'title' => ['nullable', 'string', 'max:120'],
			'body' => ['nullable', 'string', 'max:5000'],
			'images' => ['required', 'array', 'min:1', 'max:20'],
			'images.*' => ['required', 'file', 'max:512000', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,video/mp4,video/quicktime,video/webm'],
		];
	}
}
