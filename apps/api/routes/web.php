<?php

use Illuminate\Support\Facades\Route;

// This is an API server only: no pages. The root and every unknown address answer 404,
// so the server reveals nothing to anyone browsing it.
Route::get('/', fn () => abort(404));
