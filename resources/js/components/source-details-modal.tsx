import { X } from 'lucide-react';
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
        : 'Sin captura';
    const canShowThumbnail = Boolean(source.backupPath) && !thumbnailUnavailable;
    const thumbnailUrl = `/hemeroteca/sources/${source.id}/backup/thumbnail`;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-4" onClick={onClose}>
            <div
                className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-800 dark:bg-slate-950"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="source-details-title"
            >
                <div className="flex items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h2 id="source-details-title" className="text-2xl font-semibold tracking-tight text-foreground">
                            {source.name}
                        </h2>
                        <p className="text-sm text-muted-foreground">Detalle completo de la fuente seleccionada.</p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        onClick={onClose}
                        aria-label="Cerrar detalle"
                        className="h-8 w-8 shrink-0"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                <div className="mt-4 space-y-5">
                    <div className="space-y-2">
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">Miniatura</p>
                        <div className="overflow-hidden rounded-lg border border-slate-200/80 bg-slate-100 dark:border-slate-800 dark:bg-slate-900/40">
                            {canShowThumbnail ? (
                                <img
                                    src={thumbnailUrl}
                                    alt={`Miniatura de ${source.name}`}
                                    className="h-52 w-full object-cover object-top sm:h-64"
                                    loading="lazy"
                                    onError={() => setThumbnailUnavailable(true)}
                                />
                            ) : (
                                <div className="flex h-52 items-center justify-center px-4 text-center text-sm text-muted-foreground sm:h-64">
                                    No hay miniatura disponible para esta fuente.
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button asChild size="sm" className="h-9">
                            <a
                                href={`/hemeroteca/sources/${source.id}/backup`}
                                target="_blank"
                                rel="noreferrer"
                                aria-disabled={!source.backupPath}
                                className={!source.backupPath ? 'pointer-events-none opacity-50' : ''}
                            >
                                Abrir respaldo
                            </a>
                        </Button>
                        <Button asChild size="sm" variant="outline" className="h-9">
                            <a
                                href={`/hemeroteca/sources/${source.id}/backup/download`}
                                aria-disabled={!source.backupPath}
                                className={!source.backupPath ? 'pointer-events-none opacity-50' : ''}
                            >
                                Descargar respaldo
                            </a>
                        </Button>
                    </div>

                    <div className="grid gap-3 rounded-lg border border-slate-200/80 bg-slate-50/60 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/40 sm:grid-cols-2">
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">Fecha captura</p>
                            <p className="mt-1 font-medium">{source.date}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">Capturado por</p>
                            <p className="mt-1 font-medium">{source.capturedBy}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">ID</p>
                            <p className="mt-1 font-medium">{source.id}</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">Formato captura</p>
                            <p className="mt-1 font-medium">WACZ</p>
                        </div>
                        <div>
                            <p className="text-xs uppercase tracking-wide text-muted-foreground">Fecha captura</p>
                            <p className="mt-1 font-medium">{formattedCapturedAt}</p>
                        </div>
                        {source.oficioNumber ? (
                            <div>
                                <p className="text-xs uppercase tracking-wide text-muted-foreground">Numero de oficio</p>
                                <p className="mt-1 font-medium">{source.oficioNumber}</p>
                            </div>
                        ) : null}
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">Descripcion</p>
                        <p className="rounded-lg border border-slate-200/80 bg-white p-4 text-sm leading-relaxed dark:border-slate-800 dark:bg-slate-950">
                            {source.description}
                        </p>
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">URL</p>
                        <a
                            href={source.url}
                            target="_blank"
                            rel="noreferrer"
                            className="block truncate rounded-lg border border-cyan-200/70 bg-cyan-50 px-4 py-3 text-sm text-cyan-800 underline underline-offset-2 dark:border-cyan-900 dark:bg-cyan-950/40 dark:text-cyan-300"
                        >
                            {source.url}
                        </a>
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs uppercase tracking-wide text-muted-foreground">Etiquetas</p>
                        <div className="flex flex-wrap gap-2 rounded-lg border border-slate-200/80 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                            {tags.length > 0 ? (
                                tags.map((tag) => (
                                    <Badge key={`modal-${source.id}-${tag}`} variant="outline">
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
    );
}
