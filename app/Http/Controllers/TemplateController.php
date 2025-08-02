<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index()
    {
        $templates = Template::orderBy('usage_count', 'desc')
            ->orderBy('name')
            ->get();

        // If it's an API request, return JSON
        if (request()->wantsJson()) {
            return response()->json([
                'templates' => $templates,
            ]);
        }

        // Otherwise, render the Inertia view
        return Inertia::render('Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create()
    {
        return Inertia::render('RealtimeAgent/TemplateForm');
    }

    public function edit(Template $template)
    {
        return Inertia::render('RealtimeAgent/TemplateForm', [
            'template' => $template,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'prompt' => 'required|string',
            'category' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'talking_points' => 'nullable|array',
            'talking_points.*' => 'string',
            'additional_info' => 'nullable|array',
        ]);

        $template = Template::create([
            'name' => $request->name,
            'description' => $request->description,
            'prompt' => $request->prompt,
            'category' => $request->category ?? 'general',
            'icon' => $request->icon ?? 'MessageSquare',
            'talking_points' => $request->talking_points,
            'additional_info' => $request->additional_info,
            'variables' => $request->variables,
            'is_system' => false,
        ]);

        return response()->json([
            'template' => $template,
        ]);
    }

    public function update(Request $request, Template $template)
    {
        // Allow updating all templates, including system templates
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'prompt' => 'required|string',
            'category' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'talking_points' => 'nullable|array',
            'talking_points.*' => 'string',
            'additional_info' => 'nullable|array',
        ]);

        $template->update($request->only([
            'name',
            'description',
            'prompt',
            'category',
            'icon',
            'talking_points',
            'additional_info',
            'variables',
        ]));

        return response()->json([
            'template' => $template,
        ]);
    }

    public function destroy(Template $template)
    {
        // Prevent deletion if this is the last template
        $remainingTemplatesCount = Template::where('id', '!=', $template->id)->count();
        if ($remainingTemplatesCount === 0) {
            return response()->json([
                'error' => 'Cannot delete the last remaining template. At least one template must exist.',
            ], 422);
        }

        $template->delete();

        return response()->json([
            'success' => true,
        ]);
    }

    public function incrementUsage(Template $template)
    {
        $template->incrementUsage();

        return response()->json([
            'success' => true,
        ]);
    }
}
