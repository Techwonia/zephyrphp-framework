<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai;

class AiBuilder
{
    private AiProviderManager $providers;

    public function __construct(?AiProviderManager $providers = null)
    {
        $this->providers = $providers ?? AiProviderManager::getInstance();
    }

    /**
     * Generate a full page from a natural language description.
     */
    public function generatePage(string $prompt, array $context = [], ?string $provider = null): GeneratedPage
    {
        $systemPrompt = $this->buildPageSystemPrompt($context);

        $ai = $this->providers->provider($provider);

        $response = $ai->generate($systemPrompt, $prompt, [
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ]);

        return $this->parsePageResponse($response);
    }

    /**
     * Generate a reusable section from a description.
     */
    public function generateSection(string $prompt, array $context = [], ?string $provider = null): array
    {
        $systemPrompt = $this->buildSectionSystemPrompt($context);

        $ai = $this->providers->provider($provider);

        $response = $ai->generate($systemPrompt, $prompt, [
            'max_tokens' => 4096,
            'temperature' => 0.7,
        ]);

        return $this->parseSectionResponse($response);
    }

    /**
     * Generate page content (text, headings, copy).
     */
    public function generateContent(string $prompt, array $context = [], ?string $provider = null): AiResponse
    {
        $systemPrompt = "You are a professional copywriter for web pages. Write clear, engaging content.\n"
            . "Return ONLY the content text. Do not include HTML tags unless specifically asked.\n"
            . "Match the tone and style described by the user.";

        if (!empty($context['tone'])) {
            $systemPrompt .= "\nDesired tone: " . $context['tone'];
        }
        if (!empty($context['audience'])) {
            $systemPrompt .= "\nTarget audience: " . $context['audience'];
        }

        $ai = $this->providers->provider($provider);

        return $ai->generate($systemPrompt, $prompt, [
            'max_tokens' => 2048,
            'temperature' => 0.8,
        ]);
    }

    /**
     * Explain what a template/code does.
     */
    public function explainCode(string $code, ?string $provider = null): AiResponse
    {
        $systemPrompt = "You are a ZephyrPHP template expert. Explain what the given Twig template code does in clear, simple language.\n"
            . "Break down each section and explain the purpose of template tags, variables, and logic.\n"
            . "Keep explanations concise and beginner-friendly.";

        $ai = $this->providers->provider($provider);

        return $ai->generate($systemPrompt, "Explain this template:\n\n```twig\n{$code}\n```", [
            'max_tokens' => 2048,
            'temperature' => 0.3,
        ]);
    }

    /**
     * Fix broken template code.
     */
    public function fixTemplate(string $code, string $error = '', ?string $provider = null): AiResponse
    {
        $systemPrompt = "You are a ZephyrPHP template expert. Fix the broken Twig template code.\n"
            . "Return ONLY the corrected code inside a ```twig code block. No explanations before or after.\n"
            . "Preserve the original intent and structure as much as possible.";

        $userPrompt = "Fix this broken template:\n\n```twig\n{$code}\n```";
        if ($error) {
            $userPrompt .= "\n\nError message: {$error}";
        }

        $ai = $this->providers->provider($provider);

        return $ai->generate($systemPrompt, $userPrompt, [
            'max_tokens' => 4096,
            'temperature' => 0.2,
        ]);
    }

    /**
     * Build the system prompt for page generation.
     */
    private function buildPageSystemPrompt(array $context): string
    {
        $prompt = <<<'PROMPT'
You are a ZephyrPHP page builder AI. Generate Twig templates for the ZephyrPHP CMS framework.

## Output Format
Return a JSON object with this structure (no markdown wrapping, just raw JSON):
{
  "title": "Page Title",
  "layout": "base",
  "template": "... full Twig template content ...",
  "sections": [
    {
      "type": "section-type",
      "settings": { ... }
    }
  ],
  "css": "... optional custom CSS ..."
}

## ZephyrPHP Template Rules
- Templates extend a layout: {% extends "@theme/layouts/base.twig" %}
- Content goes in {% block content %}...{% endblock %}
- Use semantic HTML5 (header, main, section, footer, article, etc.)
- Use CSS classes for styling — prefer utility-like classes
- Use {{ page.title }} for the page title
- Use {{ theme_settings().colors.primary }} for theme colors
- Sections use {% schema %} blocks for configurable settings
- Images should use placeholder paths: /assets/images/placeholder.jpg
- Links should use relative paths
- All user-facing text should be realistic, not lorem ipsum
- Make templates responsive by default (mobile-first)
- Use modern CSS (flexbox, grid, custom properties)

## Section Template Format
Each section is a standalone .twig file:
```twig
{% schema %}
{
  "name": "Section Name",
  "settings": [
    { "id": "heading", "type": "text", "label": "Heading", "default": "..." },
    { "id": "subheading", "type": "textarea", "label": "Subheading", "default": "..." }
  ]
}
{% endschema %}

<section class="section-name">
  <h2>{{ section.settings.heading }}</h2>
  <p>{{ section.settings.subheading }}</p>
</section>
```
PROMPT;

        // Add available sections context
        if (!empty($context['sections'])) {
            $prompt .= "\n\n## Available Sections\n";
            foreach ($context['sections'] as $section) {
                $prompt .= "- {$section['name']}: {$section['description']}\n";
            }
        }

        // Add theme info
        if (!empty($context['theme'])) {
            $prompt .= "\n\n## Current Theme\n";
            $prompt .= "Name: {$context['theme']['name']}\n";
            if (!empty($context['theme']['settings'])) {
                $prompt .= "Settings: " . json_encode($context['theme']['settings']) . "\n";
            }
        }

        // Add CSS framework info
        if (!empty($context['css_framework'])) {
            $prompt .= "\n\n## CSS Framework\nUsing: {$context['css_framework']}\n";
        }

        return $prompt;
    }

    /**
     * Build the system prompt for section generation.
     */
    private function buildSectionSystemPrompt(array $context): string
    {
        $prompt = <<<'PROMPT'
You are a ZephyrPHP section builder AI. Generate reusable Twig section templates.

## Output Format
Return a JSON object (no markdown wrapping, just raw JSON):
{
  "name": "Section Display Name",
  "slug": "section-slug",
  "template": "... full Twig section template content including {% schema %} block ...",
  "preview_description": "Brief description of the section",
  "css": "... optional scoped CSS ..."
}

## Section Template Format
```twig
{% schema %}
{
  "name": "Section Name",
  "tag": "section",
  "class": "section-slug",
  "settings": [
    { "id": "heading", "type": "text", "label": "Heading", "default": "Default heading" },
    { "id": "subheading", "type": "textarea", "label": "Subheading", "default": "" },
    { "id": "bg_color", "type": "color", "label": "Background Color", "default": "#ffffff" }
  ],
  "blocks": [
    {
      "type": "item",
      "name": "Item",
      "settings": [
        { "id": "title", "type": "text", "label": "Title", "default": "Item" },
        { "id": "description", "type": "textarea", "label": "Description", "default": "" }
      ]
    }
  ]
}
{% endschema %}

<section class="section-slug" style="background-color: {{ section.settings.bg_color }};">
  <div class="container">
    <h2>{{ section.settings.heading }}</h2>
    {% if section.settings.subheading %}
      <p class="subheading">{{ section.settings.subheading }}</p>
    {% endif %}
    <div class="items-grid">
      {% for block in section.blocks %}
        <div class="item">
          <h3>{{ block.settings.title }}</h3>
          <p>{{ block.settings.description }}</p>
        </div>
      {% endfor %}
    </div>
  </div>
</section>
```

## Rules
- Settings types: text, textarea, color, image, select, checkbox, range, url
- Use section.settings.* for settings values
- Use section.blocks for repeatable content blocks
- Make sections fully responsive
- Use semantic HTML
- Include sensible default values for all settings
- Keep CSS scoped to the section's root class
PROMPT;

        return $prompt;
    }

    /**
     * Parse AI response into a GeneratedPage.
     */
    private function parsePageResponse(AiResponse $response): GeneratedPage
    {
        $data = $response->json();

        if ($data && !empty($data['template'])) {
            return new GeneratedPage(
                template: $data['template'],
                title: $data['title'] ?? 'AI Generated Page',
                sections: $data['sections'] ?? [],
                settings: $data['settings'] ?? [],
                layout: $data['layout'] ?? 'base',
                css: $data['css'] ?? null,
                response: $response
            );
        }

        // JSON parsing failed or no template key — try to extract Twig template from raw content
        $content = trim($response->getContent());

        // Strip markdown code fences if present
        if (preg_match('/```(?:twig|html|jinja2?)?\s*\n([\s\S]+?)\n\s*```/', $content, $matches)) {
            $content = trim($matches[1]);
        }

        // If content looks like JSON (not Twig), try harder to extract template
        if (str_starts_with($content, '{') && !str_contains($content, '{%')) {
            $json = json_decode($content, true);
            if (is_array($json) && !empty($json['template'])) {
                return new GeneratedPage(
                    template: $json['template'],
                    title: $json['title'] ?? 'AI Generated Page',
                    sections: $json['sections'] ?? [],
                    settings: $json['settings'] ?? [],
                    layout: $json['layout'] ?? 'base',
                    css: $json['css'] ?? null,
                    response: $response
                );
            }
        }

        return new GeneratedPage(
            template: $content,
            title: 'AI Generated Page',
            response: $response
        );
    }

    /**
     * Parse AI response into section data.
     */
    private function parseSectionResponse(AiResponse $response): array
    {
        $data = $response->json();

        if ($data && !empty($data['template'])) {
            $data['usage'] = $response->toArray();
            return $data;
        }

        // Fallback: try to extract Twig from raw content
        $content = trim($response->getContent());
        if (preg_match('/```(?:twig|html|jinja2?)?\s*\n([\s\S]+?)\n\s*```/', $content, $matches)) {
            $content = trim($matches[1]);
        }

        return [
            'name' => 'AI Generated Section',
            'slug' => 'ai-section-' . time(),
            'template' => $content,
            'preview_description' => '',
            'css' => '',
            'usage' => $response->toArray(),
        ];
    }
}
