<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Review Scraper</title>

<div class="container">
    <h1>Check Reviews by Domain</h1>

    @if ($errors->any())
        <div class="alert alert-danger">
            {{ $errors->first() }}
        </div>
    @endif

    @if (session('message'))
        <div class="alert alert-info">
            {{ session('message') }}
        </div>
    @endif

    <form method="POST" action="{{ route('reviews.handle') }}">
        @csrf
        <div class="mb-3">
            <label for="domain" class="form-label">Domain name</label>
            <input type="text"
                   name="domain"
                   id="domain"
                   class="form-control"
                   placeholder="example.com"
                   value="{{ old('domain') }}">
        </div>

        <div class="mb-3">
            <label class="form-label">Sources</label>
            <div class="form-check">
                <input class="form-check-input"
                       type="checkbox"
                       name="sources[]"
                       id="source_google"
                       value="google"
                       {{ in_array('google', old('sources', ['google','trustpilot'])) ? 'checked' : '' }}>
                <label class="form-check-label" for="source_google">
                    Google Maps reviews
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input"
                       type="checkbox"
                       name="sources[]"
                       id="source_trustpilot"
                       value="trustpilot"
                       {{ in_array('trustpilot', old('sources', ['google','trustpilot'])) ? 'checked' : '' }}>
                <label class="form-check-label" for="source_trustpilot">
                    Trustpilot reviews
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            Get Reviews
        </button>
    </form>
</div>

