import { Download, ExternalLink, FileArchive, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type SourceDetails = {
    id: number;
    name: string;
    description: string;
    url: string;
    backupPath: string | null;
    tags: string[];
    date: string;
    capturedAt: string | null;
    capturedBy: string;
    oficioNumber: string | null;

};

type SourceDetailsModalProps = {
    source: SourceDetails | null;
    open: boolean;
    onClose: () => void;
};

export function SourceDetailsModal({ source, open, onClose }: SourceDetailsModalProps) {
    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [open, onClose]);

    if (!open || !source) {
        return null;
    }

    return <SourceDetailsModalContent key={`${source.id}-${open ? 'open' : 'closed'}`} source={source} onClose={onClose} />;
}

function SourceDetailsModalContent({ source, onClose }: { source: SourceDetails; onClose: () => void }) {
    const [thumbnailUnavailable, setThumbnailUnavailable] = useState(false);
    const tags = Array.isArray(source.tags) ? source.tags : [];

    const formattedCapturedAt = source.capturedAt
        ? source.capturedAt.split('-').reverse().join('/')
        : null;
    const canShowThumbnail = Boolean(source.backupPath) && !thumbnailUnavailable;
    const thumbnailUrl = `/hemeroteca/sources/${source.id}/backup/thumbnail`;

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            onClick={onClose}
        >
            <div
                className="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-950"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="source-details-title"
            >
                {/* Modal header */}
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10">
                            <FileArchive className="h-4.5 w-4.5 text-primary" />
                        </div>
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <h2
                                    id="source-details-title"
                                    className="truncate text-base font-bold tracking-tight text-foreground"
                                >
                                    {source.name}
                                </h2>
                                <span className="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    #{source.id}
                                </span>
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">Detalle completo de la fuente</p>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onClose}
                        aria-label="Cerrar detalle"
                        className="h-8 w-8 shrink-0 rounded-lg"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                {/* Scrollable body */}
                <div className="flex-1 overflow-y-auto">
                    {/* Thumbnail */}
                    <div className="relative h-52 w-full overflow-hidden bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-900 sm:h-60">
                        {canShowThumbnail ? (
                            <img
                                src={thumbnailUrl}
                                alt={`Miniatura de ${source.name}`}
                                className="h-full w-full object-cover object-top"
                                loading="lazy"
                                onError={() => setThumbnailUnavailable(true)}
                            />
                        ) : (
                            <div className="flex h-full flex-col items-center justify-center gap-2">
                                <FileArchive className="h-10 w-10 text-slate-300 dark:text-slate-600" />
                                <p className="text-xs text-muted-foreground">Sin miniatura disponible</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-5 p-6">
                        {/* Action buttons */}
                        <div className="flex flex-wrap gap-2">
                            <Button
                                asChild
                                size="sm"
                                className="gap-2"
                                disabled={!source.backupPath}
                            >
                                <a
                                    href={`/hemeroteca/sources/${source.id}/backup`}
                                    target="_blank"
                                    rel="noreferrer"
                                    aria-disabled={!source.backupPath}
                                    className={!source.backupPath ? 'pointer-events-none opacity-50' : ''}
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                    Abrir respaldo
                                </a>
                            </Button>
                            <Button asChild size="sm" variant="outline" className="gap-2" disabled={!source.backupPath}>
                                <a
                                    href={`/hemeroteca/sources/${source.id}/backup/download`}
                                    aria-disabled={!source.backupPath}
                                    className={!source.backupPath ? 'pointer-events-none opacity-50' : ''}
                                >
                                    <Download className="h-3.5 w-3.5" />
                                    Descargar
                                </a>
                            </Button>
                        </div>

                        {/* Metadata grid */}
                        <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                            <div className="grid grid-cols-2 divide-x divide-y divide-slate-100 dark:divide-slate-800 sm:grid-cols-3">
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        ID
                                    </p>
                                    <p className="mt-1 font-mono text-sm font-semibold text-foreground">
                                        #{source.id}
                                    </p>
                                </div>
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        Formato
                                    </p>
                                    <p className="mt-1 text-sm font-medium text-foreground">WACZ</p>
                                </div>
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        Fecha registro
                                    </p>
                                    <p className="mt-1 text-sm font-medium text-foreground">{source.date}</p>
                                </div>
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        Capturado por
                                    </p>
                                    <p className="mt-1 text-sm font-medium text-foreground">{source.capturedBy}</p>
                                </div>
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        Fecha captura
                                    </p>
                                    <p className="mt-1 text-sm font-medium text-foreground">
                                        {formattedCapturedAt ?? '—'}
                                    </p>
                                </div>
                                {source.oficioNumber && (
                                    <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                        <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                            N.º oficio
                                        </p>
                                        <p className="mt-1 text-sm font-medium text-foreground">
                                            {source.oficioNumber}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* URL */}
                        <div className="space-y-1.5">
                            <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                URL
                            </p>
                            <a
                                href={source.url}
                                target="_blank"
                                rel="noreferrer"
                                className="flex items-center gap-2 truncate rounded-lg border border-sky-200/70 bg-sky-50 px-4 py-3 text-sm text-sky-700 transition-colors hover:bg-sky-100 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300 dark:hover:bg-sky-950/60"
                            >
                                <ExternalLink className="h-3.5 w-3.5 shrink-0" />
                                <span className="truncate">{source.url}</span>
                            </a>
                        </div>

                        {/* Description */}
                        <div className="space-y-1.5">
                            <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                Descripción
                            </p>
                            <p className="rounded-xl border border-slate-200 bg-white p-4 text-sm leading-relaxed text-foreground dark:border-slate-800 dark:bg-slate-950">
                                {source.description}
                            </p>
                        </div>

                        {/* Tags */}
                        <div className="space-y-1.5">
                            <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                Etiquetas
                            </p>
                            <div className="flex min-h-[44px] flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                                {tags.length > 0 ? (
                                    tags.map((tag) => (
                                        <Badge
                                            key={`modal-${source.id}-${tag}`}
                                            variant="outline"
                                            className="rounded-md text-xs font-medium"
                                        >
                                            {tag}
                                        </Badge>
                                    ))
                                ) : (
                                    <span className="text-sm text-muted-foreground">Sin etiquetas</span>
                                )}
                            </div>
                        </div>


                    </div>
                </div>
            </div>
        </div>
    );
}
