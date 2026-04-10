import { router } from '@inertiajs/react';
import { Download, ExternalLink, FileArchive, Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

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

type BackupImage = {
    index: number;
    name: string;
    url: string;
};

type SourceDetailsModalProps = {
    source: SourceDetails | null;
    open: boolean;
    onClose: () => void;
    canEdit?: boolean;
    canDelete?: boolean;
    suggestedTags?: string[];
};

export function SourceDetailsModal({ source, open, onClose, canEdit, canDelete, suggestedTags }: SourceDetailsModalProps) {
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

    return <SourceDetailsModalContent key={`${source.id}-${source.backupPath ?? 'no-backup'}-${open ? 'open' : 'closed'}`} source={source} onClose={onClose} canEdit={canEdit} canDelete={canDelete} suggestedTags={suggestedTags} />;
}

function SourceDetailsModalContent({ source, onClose, canEdit, canDelete, suggestedTags = [] }: { source: SourceDetails; onClose: () => void; canEdit?: boolean; canDelete?: boolean; suggestedTags?: string[] }) {
    const [thumbnailUnavailable, setThumbnailUnavailable] = useState(false);
    const [backupImages, setBackupImages] = useState<BackupImage[]>([]);
    const [selectedImageIndex, setSelectedImageIndex] = useState(0);
    const [isLoadingBackupImages, setIsLoadingBackupImages] = useState(Boolean(source.backupPath));
    const [isEditing, setIsEditing] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);

    const [editTitle, setEditTitle] = useState(source.name);
    const [editDescription, setEditDescription] = useState(source.description);
    const [editUrl, setEditUrl] = useState(source.url);
    const [editTags, setEditTags] = useState<string[]>(Array.isArray(source.tags) ? source.tags : []);
    const [tagInput, setTagInput] = useState('');
    const [showTagSuggestions, setShowTagSuggestions] = useState(false);
    const tagInputRef = useRef<HTMLInputElement>(null);

    const tags = Array.isArray(source.tags) ? source.tags : [];
    const thumbnailUrl = `/hemeroteca/sources/${source.id}/backup/thumbnail`;

    useEffect(() => {
        if (!source.backupPath) {
            return;
        }

        const controller = new AbortController();

        void fetch(`/hemeroteca/sources/${source.id}/backup/images`, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error('No se pudo cargar la galeria de respaldo.');
                }

                const payload = await response.json();
                const images = Array.isArray(payload?.images) ? payload.images : [];
                const normalized = images
                    .filter((item): item is BackupImage => (
                        item
                        && typeof item.index === 'number'
                        && typeof item.name === 'string'
                        && typeof item.url === 'string'
                    ))
                    .sort((left, right) => left.index - right.index);

                setBackupImages(normalized);
                setSelectedImageIndex(0);
            })
            .catch((error: unknown) => {
                if (error instanceof Error && error.name === 'AbortError') {
                    return;
                }

                setBackupImages([]);
            })
            .finally(() => {
                setIsLoadingBackupImages(false);
            });

        return () => {
            controller.abort();
        };
    }, [source.id, source.backupPath]);

    const selectedBackupImage = backupImages[selectedImageIndex] ?? backupImages[0] ?? null;
    const previewImageUrl = selectedBackupImage?.url ?? (source.backupPath && !thumbnailUnavailable ? thumbnailUrl : null);

    const startEditing = () => {
        setEditTitle(source.name);
        setEditDescription(source.description);
        setEditUrl(source.url);
        setEditTags(Array.isArray(source.tags) ? source.tags : []);
        setTagInput('');
        setIsEditing(true);
    };

    const cancelEditing = () => {
        setIsEditing(false);
        setTagInput('');
    };

    const handleSave = () => {
        setIsSaving(true);
        router.patch(
            `/hemeroteca/sources/${source.id}`,
            {
                title: editTitle.trim(),
                description: editDescription.trim(),
                url: editUrl.trim(),
                tags: editTags,
            },
            {
                onSuccess: () => {
                    setIsSaving(false);
                    setIsEditing(false);
                    onClose();
                },
                onError: () => {
                    setIsSaving(false);
                },
            },
        );
    };

    const handleDelete = () => {
        if (!window.confirm(`Eliminar la fuente #${source.id}? Esta accion no se puede deshacer.`)) {
            return;
        }

        setIsDeleting(true);
        router.delete(`/hemeroteca/sources/${source.id}`, {
            onSuccess: () => {
                setIsDeleting(false);
                onClose();
            },
            onError: () => {
                setIsDeleting(false);
            },
        });
    };

    const addEditTag = (tag: string) => {
        const trimmed = tag.trim();

        if (!trimmed || editTags.some((t) => t.toLowerCase() === trimmed.toLowerCase())) {
return;
}

        setEditTags([...editTags, trimmed]);
        setTagInput('');
        setShowTagSuggestions(false);
    };

    const removeEditTag = (tag: string) => {
        setEditTags(editTags.filter((t) => t !== tag));
    };

    const filteredSuggestions = suggestedTags
        .filter((t) => t.trim())
        .filter((t) => !editTags.some((et) => et.toLowerCase() === t.toLowerCase()))
        .filter((t) => !tagInput.trim() || t.toLowerCase().includes(tagInput.trim().toLowerCase()));

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
                                <span className="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    #{source.id}
                                </span>
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {isEditing ? 'Editando fuente' : 'Detalle completo de la fuente'}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-1">
                        {canEdit && !isEditing && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={startEditing}
                                aria-label="Editar fuente"
                                className="h-8 w-8 rounded-lg"
                            >
                                <Pencil className="h-4 w-4" />
                            </Button>
                        )}
                        {isEditing && (
                            <>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={cancelEditing}
                                    disabled={isSaving}
                                    className="h-8 gap-1.5 rounded-lg text-xs"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Cancelar
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={handleSave}
                                    disabled={isSaving || editTitle.trim() === '' || editUrl.trim() === ''}
                                    className="h-8 gap-1.5 rounded-lg text-xs"
                                >
                                    <Save className="h-3.5 w-3.5" />
                                    {isSaving ? 'Guardando…' : 'Guardar'}
                                </Button>
                            </>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            onClick={onClose}
                            aria-label="Cerrar detalle"
                            className="h-8 w-8 rounded-lg"
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                </div>

                {/* Scrollable body */}
                <div className="flex-1 overflow-y-auto">
                    {/* Thumbnail */}
                    <div className="relative h-52 w-full overflow-hidden bg-gradient-to-br from-slate-100 to-slate-200 dark:from-slate-800 dark:to-slate-900 sm:h-60">
                        {previewImageUrl ? (
                            <img
                                src={previewImageUrl}
                                alt={`Miniatura de ${source.name}`}
                                className="h-full w-full object-cover object-top"
                                loading="lazy"
                                onError={() => {
                                    if (!selectedBackupImage) {
                                        setThumbnailUnavailable(true);
                                    }
                                }}
                            />
                        ) : (
                            <div className="flex h-full flex-col items-center justify-center gap-2">
                                <FileArchive className="h-10 w-10 text-slate-300 dark:text-slate-600" />
                                <p className="text-xs text-muted-foreground">Sin imagen disponible</p>
                            </div>
                        )}
                    </div>
                    {backupImages.length > 1 && (
                        <div className="border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                            <div className="flex gap-2 overflow-x-auto pb-1">
                                {backupImages.map((image, index) => {
                                    const isActive = selectedBackupImage?.index === image.index;

                                    return (
                                        <button
                                            key={`backup-image-${source.id}-${image.index}`}
                                            type="button"
                                            onClick={() => setSelectedImageIndex(index)}
                                            className={`shrink-0 overflow-hidden rounded-lg border transition-colors ${
                                                isActive
                                                    ? 'border-primary ring-2 ring-primary/20'
                                                    : 'border-slate-200 hover:border-slate-300 dark:border-slate-700 dark:hover:border-slate-600'
                                            }`}
                                            aria-label={`Ver imagen ${index + 1}`}
                                        >
                                            <img
                                                src={image.url}
                                                alt={image.name}
                                                className="h-14 w-20 object-cover object-top"
                                                loading="lazy"
                                            />
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    {isLoadingBackupImages && (
                        <div className="border-b border-slate-100 px-4 py-2 text-xs text-muted-foreground dark:border-slate-800">
                            Cargando imagenes del respaldo...
                        </div>
                    )}

                    {isEditing ? (
                        <div className="space-y-5 p-6">
                            {/* Title */}
                            <div className="space-y-1.5">
                                <label className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground" htmlFor="edit-title">
                                    Título
                                </label>
                                <Input
                                    id="edit-title"
                                    value={editTitle}
                                    onChange={(e) => setEditTitle(e.target.value)}
                                    className="text-sm"
                                />
                            </div>

                            {/* URL */}
                            <div className="space-y-1.5">
                                <label className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground" htmlFor="edit-url">
                                    URL
                                </label>
                                <Input
                                    id="edit-url"
                                    type="url"
                                    value={editUrl}
                                    onChange={(e) => setEditUrl(e.target.value)}
                                    className="text-sm"
                                />
                            </div>

                            {/* Description */}
                            <div className="space-y-1.5">
                                <label className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground" htmlFor="edit-description">
                                    Descripción
                                </label>
                                <textarea
                                    id="edit-description"
                                    value={editDescription}
                                    onChange={(e) => setEditDescription(e.target.value)}
                                    rows={4}
                                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                />
                            </div>

                            {/* Tags */}
                            <div className="space-y-1.5">
                                <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                    Etiquetas
                                </p>
                                <div className="flex min-h-[40px] max-h-32 flex-wrap gap-2 overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 pr-2 dark:border-slate-800 dark:bg-slate-950">
                                    {editTags.map((tag) => (
                                        <Badge key={tag} variant="outline" className="gap-1 rounded-md text-xs font-medium">
                                            {tag}
                                            <button
                                                type="button"
                                                onClick={() => removeEditTag(tag)}
                                                aria-label={`Quitar etiqueta ${tag}`}
                                                className="ml-0.5 text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="h-2.5 w-2.5" />
                                            </button>
                                        </Badge>
                                    ))}
                                </div>
                                <div className="relative">
                                    <Input
                                        ref={tagInputRef}
                                        value={tagInput}
                                        onChange={(e) => {
 setTagInput(e.target.value); setShowTagSuggestions(true); 
}}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter' || (e.key === 'Tab' && tagInput.trim() !== '')) {
 e.preventDefault(); addEditTag(tagInput); 
}
                                        }}
                                        onFocus={() => setShowTagSuggestions(true)}
                                        onBlur={() => setTimeout(() => setShowTagSuggestions(false), 150)}
                                        placeholder="Añadir etiqueta (Enter o Tab)…"
                                        className="h-8 pr-8 text-sm"
                                    />
                                    <button
                                        type="button"
                                        onMouseDown={() => addEditTag(tagInput)}
                                        aria-label="Añadir etiqueta"
                                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                    </button>
                                    {showTagSuggestions && filteredSuggestions.length > 0 && (
                                        <div className="absolute left-0 top-full z-50 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                            <div className="max-h-40 overflow-y-auto p-1">
                                                {filteredSuggestions.map((tag) => (
                                                    <button
                                                        key={tag}
                                                        type="button"
                                                        onMouseDown={() => addEditTag(tag)}
                                                        className="flex w-full items-center rounded-md px-3 py-1.5 text-left text-sm transition-colors hover:bg-slate-100 dark:hover:bg-slate-800"
                                                    >
                                                        {tag}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    ) : (
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
                            {canDelete && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="destructive"
                                    className="gap-2"
                                    disabled={isDeleting}
                                    onClick={handleDelete}
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                    {isDeleting ? 'Eliminando…' : 'Eliminar fuente'}
                                </Button>
                            )}
                        </div>

                        {/* Metadata grid */}
                        <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
                            <div className="grid grid-cols-2 divide-x divide-y divide-slate-100 dark:divide-slate-800 sm:grid-cols-2">
                                <div className="bg-slate-50/50 px-4 py-3 dark:bg-slate-900/30">
                                    <p className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                        ID
                                    </p>
                                    <p className="mt-1 text-sm font-semibold text-foreground">
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
                                        N.º oficio
                                    </p>
                                    <p className="mt-1 text-sm font-medium text-foreground">
                                        {source.oficioNumber && source.oficioNumber.trim() !== '' ? source.oficioNumber : 'Sin oficio'}
                                    </p>
                                </div>
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
                            <div className="flex min-h-[44px] max-h-32 flex-wrap gap-2 overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 pr-2 dark:border-slate-800 dark:bg-slate-950">
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
                    )}
                </div>
            </div>
        </div>
    );
}
