<?php
use App\Helpers\UrlHelper;

function url(string $path): string {
    return UrlHelper::to($path);
}

function asset(string $path): string {
    return UrlHelper::asset($path);
}
