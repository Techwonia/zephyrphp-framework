<?php

declare(strict_types=1);

namespace ZephyrPHP\Ai;

class GeneratedPage
{
    private string $template;
    private string $title;
    private array $sections;
    private array $settings;
    private ?string $layout;
    private ?string $css;
    private AiResponse $response;

    public function __construct(
        string $template,
        string $title,
        array $sections = [],
        array $settings = [],
        ?string $layout = null,
        ?string $css = null,
        ?AiResponse $response = null
    ) {
        $this->template = $template;
        $this->title = $title;
        $this->sections = $sections;
        $this->settings = $settings;
        $this->layout = $layout;
        $this->css = $css;
        $this->response = $response ?? new AiResponse('', '', '', 0, 0);
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSections(): array
    {
        return $this->sections;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function getLayout(): ?string
    {
        return $this->layout;
    }

    public function getCss(): ?string
    {
        return $this->css;
    }

    public function getResponse(): AiResponse
    {
        return $this->response;
    }

    public function toArray(): array
    {
        return [
            'template' => $this->template,
            'title' => $this->title,
            'sections' => $this->sections,
            'settings' => $this->settings,
            'layout' => $this->layout,
            'css' => $this->css,
            'usage' => $this->response->toArray(),
        ];
    }
}
