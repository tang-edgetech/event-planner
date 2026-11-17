<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Microsite;
use App\Support\Audit;
use App\Support\Html;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MicrositeController extends Controller
{
    /** Section types of the page builder (same as the WordPress build, minus "News", which had no source here). */
    public const LAYOUTS = ['banner', 'text', 'image_text', 'image', 'gallery', 'speakers', 'agenda', 'cards', 'logos', 'video', 'contact', 'form', 'html'];

    /** Rich-text keys inside sections that are cleaned before saving. */
    private const HTML_KEYS = ['content', 'description', 'intro', 'bio'];

    public function index(Request $request): JsonResponse
    {
        $sites = Microsite::query()->with('event:id,title')
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")->orWhere('slug', 'like', "%{$t}%")))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('updated_at')
            ->paginate(min(100, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($sites->items())->map(fn (Microsite $m) => [
                'id' => $m->id, 'title' => $m->title, 'slug' => $m->slug, 'status' => $m->status,
                'event' => $m->event ? ['id' => $m->event->id, 'title' => $m->event->title] : null,
                'sections_count' => count($m->sections ?? []),
                'updated_at' => $m->updated_at?->toIso8601String(),
            ])->all(),
            'meta' => ['total' => $sites->total(), 'page' => $sites->currentPage(), 'per_page' => $sites->perPage()],
        ]);
    }

    public function show(Microsite $microsite): JsonResponse
    {
        return response()->json(['data' => $this->detail($microsite)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $data['slug'] = $this->uniqueSlug(($data['slug'] ?? '') ?: $data['title']);
        $data['sections'] = $this->cleanSections($data['sections'] ?? [[
            'id' => (string) Str::uuid(), 'layout' => 'banner',
            'data' => ['title' => $data['title'], 'style' => 'center', 'show_meta' => true, 'height' => 'md', 'text_align' => 'center', 'buttons' => []],
            'settings' => ['bg_type' => 'color', 'bg_color' => '#7a0f3d', 'text_scheme' => 'light', 'padding' => 'lg', 'container' => 'default'],
        ]]);
        $data['settings'] = $this->cleanSettings($data['settings'] ?? []);
        $data['created_by'] = $request->user()->id;
        $site = Microsite::query()->create($data);

        Audit::log('microsite.created', "{$request->user()->name} created the microsite \"{$site->title}\"", "{$request->user()->name} created a new microsite \"{$site->title}\" (status: {$site->status}). Its public address is /e/{$site->slug}.", 'success', $site);

        return response()->json(['data' => $this->detail($site)], 201);
    }

    public function update(Request $request, Microsite $microsite): JsonResponse
    {
        $data = $request->validate($this->rules());
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?: ($data['title'] ?? $microsite->title), $microsite->id);
        }
        if (array_key_exists('sections', $data)) {
            $data['sections'] = $this->cleanSections($data['sections'] ?? []);
        }
        if (array_key_exists('settings', $data)) {
            $data['settings'] = $this->cleanSettings($data['settings'] ?? []);
        }
        $microsite->fill($data);
        $changed = array_keys($microsite->getDirty());
        $microsite->save();

        if ($changed) {
            $names = array_map(fn ($c) => ['sections' => 'page sections', 'settings' => 'general settings', 'slug' => 'web address', 'event_id' => 'linked event'][$c] ?? $c, $changed);
            Audit::log('microsite.updated', "{$request->user()->name} updated the microsite \"{$microsite->title}\"", "{$request->user()->name} saved changes to the ".implode(', ', $names)." of the microsite \"{$microsite->title}\".".($microsite->status === 'published' ? ' The changes are live now.' : ''), 'success', $microsite, ['changed' => $changed]);
        }

        return response()->json(['data' => $this->detail($microsite->fresh())]);
    }

    /** Store unsaved changes for 30 minutes so "Preview changes" can show them on the public site. */
    public function preview(Request $request, Microsite $microsite): JsonResponse
    {
        $data = $request->validate($this->rules());
        $token = Str::random(40);
        Cache::put('microsite-preview:'.$token, [
            'id' => $microsite->id,
            'title' => $data['title'] ?? $microsite->title,
            'event_id' => array_key_exists('event_id', $data) ? $data['event_id'] : $microsite->event_id,
            'sections' => $this->cleanSections($data['sections'] ?? $microsite->sections ?? []),
            'settings' => $this->cleanSettings($data['settings'] ?? $microsite->settings ?? []),
        ], now()->addMinutes(30));

        return response()->json(['token' => $token, 'path' => '/e/'.$microsite->slug.'?preview='.$token]);
    }

    public function duplicate(Request $request, Microsite $microsite): JsonResponse
    {
        $copy = $microsite->replicate();
        $copy->title = $microsite->title.' (copy)';
        $copy->slug = $this->uniqueSlug($microsite->slug.'-copy');
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->save();
        Audit::log('microsite.duplicated', "{$request->user()->name} duplicated \"{$microsite->title}\"", "{$request->user()->name} made a draft copy of the microsite \"{$microsite->title}\" called \"{$copy->title}\".", 'success', $copy);

        return response()->json(['data' => $this->detail($copy)], 201);
    }

    public function destroy(Request $request, Microsite $microsite): JsonResponse
    {
        $microsite->delete();
        Audit::log('microsite.deleted', "{$request->user()->name} deleted the microsite \"{$microsite->title}\"", "{$request->user()->name} deleted the microsite \"{$microsite->title}\". Its address /e/{$microsite->slug} now shows \"Page not found\".", 'success', $microsite);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating = false): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'event_id' => ['sometimes', 'nullable', 'integer', 'exists:events,id'],
            'sections' => ['sometimes', 'nullable', 'array', 'max:60'],
            'sections.*.layout' => ['required', Rule::in(self::LAYOUTS)],
            'sections.*.data' => ['nullable', 'array'],
            'sections.*.settings' => ['nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return list<array<string, mixed>>
     */
    private function cleanSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            if (! is_array($s) || ! in_array($s['layout'] ?? '', self::LAYOUTS, true)) {
                continue;
            }
            $out[] = [
                'id' => is_string($s['id'] ?? null) && $s['id'] !== '' ? substr($s['id'], 0, 40) : (string) Str::uuid(),
                'layout' => $s['layout'],
                'data' => $this->cleanValue($s['data'] ?? [], $s['layout']),
                'settings' => $this->cleanValue($s['settings'] ?? [], $s['layout']),
            ];
        }

        return $out;
    }

    private function cleanValue(mixed $value, string $layout, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $k => $v) {
                $clean[$k] = $this->cleanValue($v, $layout, is_string($k) ? $k : $key);
            }

            return $clean;
        }
        if (is_string($value)) {
            if ($layout === 'html' && $key === 'code') {
                return mb_substr($value, 0, 100000); // custom embed code: kept as typed (managers and above only)
            }
            if (in_array($key, self::HTML_KEYS, true)) {
                return Html::clean($value);
            }

            return mb_substr(strip_tags($value), 0, 5000);
        }

        return is_bool($value) || is_int($value) || is_float($value) || $value === null ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function cleanSettings(array $settings): array
    {
        $color = fn ($v) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : null;

        return [
            'accent_color' => $color($settings['accent_color'] ?? null),
            'page_bg' => $color($settings['page_bg'] ?? null),
            'show_nav' => (bool) ($settings['show_nav'] ?? true),
            'nav_logo' => is_string($settings['nav_logo'] ?? null) ? strip_tags($settings['nav_logo']) : null,
            'nav_cta_label' => is_string($settings['nav_cta_label'] ?? null) ? mb_substr(strip_tags($settings['nav_cta_label']), 0, 60) : null,
            'nav_cta_url' => is_string($settings['nav_cta_url'] ?? null) ? mb_substr(strip_tags($settings['nav_cta_url']), 0, 1000) : null,
            'footer_text' => is_string($settings['footer_text'] ?? null) ? mb_substr(strip_tags($settings['footer_text']), 0, 500) : '© Copyright Protected. All Rights Reserved.',
            'animations' => (bool) ($settings['animations'] ?? true),
            'custom_css' => is_string($settings['custom_css'] ?? null) ? mb_substr(str_ireplace(['</style', '<script'], '', $settings['custom_css']), 0, 20000) : '',
        ];
    }

    private function uniqueSlug(string $source, ?int $ignore = null): string
    {
        $base = Str::slug($source) ?: 'site';
        $slug = $base;
        $n = 2;
        while (Microsite::withTrashed()->where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Microsite $m): array
    {
        return [
            'id' => $m->id, 'title' => $m->title, 'slug' => $m->slug, 'status' => $m->status, 'event_id' => $m->event_id,
            'sections' => $m->sections ?? [], 'settings' => $this->cleanSettings($m->settings ?? []),
            'updated_at' => $m->updated_at?->toIso8601String(), 'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
