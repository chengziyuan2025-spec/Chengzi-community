<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
			'images.*' => ['required', 'file', 'max:256000', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,video/mp4,video/quicktime,video/webm'],
		];
	}

	public function withValidator(Validator $validator): void
	{
		$validator->after(function (Validator $validator): void {
			$totalBytes = 0;
			foreach ((array) $this->file('images', []) as $index => $file) {
				if (!is_object($file) || !$file->isValid()) {
					continue;
				}
				$bytes = (int) $file->getSize();
				$totalBytes += $bytes;
				$mime = (string) $file->getMimeType();
				if (str_starts_with($mime, 'image/') && $bytes > 25 * 1024 * 1024) {
					$validator->errors()->add("images.$index", '单张图片不能超过 25MB。');
				}
				if (str_starts_with($mime, 'video/') && $bytes > 250 * 1024 * 1024) {
					$validator->errors()->add("images.$index", '单个视频不能超过 250MB。');
				}
			}
			if ($totalBytes > 500 * 1024 * 1024) {
				$validator->errors()->add('images', '单次发布的媒体总大小不能超过 500MB。');
			}
		});
	}
}
