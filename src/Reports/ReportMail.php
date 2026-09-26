<?php

namespace IsrarMinhas\FilamentAiVisibility\Reports;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ReportMail extends Mailable
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public array $report,
        public bool $attachPdf = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "AI visibility report: {$this->report['brand']->name}, " . $this->report['from']->format('M j') . '–' . $this->report['until']->format('M j'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'ai-visibility::mail.report', with: $this->report);
    }

    /**
     * @return array<Attachment>
     */
    public function attachments(): array
    {
        if (! $this->attachPdf || ! static::canMakePdf()) {
            return [];
        }

        $html = view('ai-visibility::mail.report', $this->report + ['forPdf' => true])->render();

        return [
            Attachment::fromData(fn () => static::pdf($html), 'ai-visibility-' . str($this->report['brand']->name)->slug() . '-' . $this->report['until']->format('Y-m-d') . '.pdf')
                ->withMime('application/pdf'),
        ];
    }

    /**
     * PDFs need the optional dompdf/dompdf package.
     */
    public static function canMakePdf(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /**
     * DejaVu Sans ships with dompdf and, unlike its built-in fonts, has the
     * arrows and accented letters reports use.
     */
    public static function pdf(string $html): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
