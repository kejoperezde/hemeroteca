import { router } from '@inertiajs/react';
import { Camera, Loader2, Plus, UploadCloud, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { createWorker } from 'tesseract.js';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/sonner';

type ManualSourceModalProps = {
    open: boolean;
    onClose: () => void;
    suggestedTags?: string[];
};

type PreviewImage = {
    name: string;
    url: string;
};

const getCsrfToken = (): string => {
    const xsrfCookie = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];

    return xsrfCookie ? decodeURIComponent(xsrfCookie) : '';
};

export function ManualSourceModal({ open, onClose, suggestedTags = [] }: ManualSourceModalProps) {
    const [url, setUrl] = useState('');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [isRequestLetter, setIsRequestLetter] = useState(false);
    const [requestLetterNumber, setRequestLetterNumber] = useState('');
    const [tagInput, setTagInput] = useState('');
    const [tags, setTags] = useState<string[]>([]);
    const [isTagInputFocused, setIsTagInputFocused] = useState(false);
    const [images, setImages] = useState<File[]>([]);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [isRunningOcr, setIsRunningOcr] = useState(false);

    const previewImages = useMemo<PreviewImage[]>(() => {
        return images.map((image) => ({
            name: image.name,
            url: URL.createObjectURL(image),
        }));
    }, [images]);

    useEffect(() => {
        return () => {
            previewImages.forEach((preview) => URL.revokeObjectURL(preview.url));
        };
    }, [previewImages]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !isSubmitting) {
                onClose();
            }
        };

        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open, onClose, isSubmitting]);

    if (!open) {
        return null;
    }

    const filteredTagSuggestions = suggestedTags
        .map((tag) => tag.trim())
        .filter(Boolean)
        .filter((tag) => !tags.some((selectedTag) => selectedTag.toLowerCase() === tag.toLowerCase()))
        .filter((tag) => !tagInput.trim() || tag.toLowerCase().includes(tagInput.trim().toLowerCase()))
        .slice(0, 8);

    const addTag = (value: string) => {
        const normalized = value.trim().replace(/\s+/g, ' ');

        if (!normalized) {
            return;
        }

        const alreadyExists = tags.some((tag) => tag.toLowerCase() === normalized.toLowerCase());

        if (alreadyExists) {
            return;
        }

        setTags((prev) => [...prev, normalized]);
    };

    const handleAddTag = () => {
        addTag(tagInput);
        setTagInput('');
    };

    const handleImageSelection = (fileList: FileList | null) => {
        if (!fileList) {
            return;
        }

        const selected = Array.from(fileList).filter((file) => file.type.startsWith('image/'));

        if (selected.length === 0) {
            toast.error('Selecciona al menos una imagen valida.');

            return;
        }

        setImages((prev) => {
            const next = [...prev, ...selected];

            return next.slice(0, 20);
        });
    };

    const removeImage = (index: number) => {
        setImages((prev) => prev.filter((_, i) => i !== index));
    };

    const resetForm = () => {
        setUrl('');
        setName('');
        setDescription('');
        setIsRequestLetter(false);
        setRequestLetterNumber('');
        setTagInput('');
        setTags([]);
        setImages([]);
        setIsSubmitting(false);
    };

    const closeAndReset = () => {
        if (isSubmitting) {
            return;
        }

        resetForm();
        onClose();
    };

    const handleSubmit = async () => {
        if (!name.trim()) {
            toast.error('El titulo es obligatorio.');

            return;
        }

        if (isRequestLetter && !requestLetterNumber.trim()) {
            toast.error('El numero de oficio es obligatorio cuando se marca oficio de peticion.');

            return;
        }

        if (images.length === 0) {
            toast.error('Debes subir al menos una imagen o captura.');

            return;
        }

        setIsSubmitting(true);

        try {
            setIsRunningOcr(true);
            const worker = await createWorker('spa+eng');
            const textChunks: string[] = [];

            try {
                for (const image of images) {
                    const imageUrl = URL.createObjectURL(image);

                    try {
                        const { data } = await worker.recognize(imageUrl);
                        const chunk = typeof data?.text === 'string'
                            ? data.text.replace(/[ \t]+\n/g, '\n').trim()
                            : '';

                        if (chunk !== '') {
                            textChunks.push(chunk);
                        }
                    } finally {
                        URL.revokeObjectURL(imageUrl);
                    }
                }
            } finally {
                await worker.terminate();
                setIsRunningOcr(false);
            }

            const extractedText = textChunks.join('\n\n').trim();

            const payload = new FormData();
            payload.append('url', url.trim());
            payload.append('name', name.trim());
            payload.append('description', description.trim());
            payload.append('text', extractedText);
            payload.append('isRequestLetter', isRequestLetter ? '1' : '0');
            payload.append('oficioNumber', requestLetterNumber.trim());
            tags.forEach((tag, index) => {
                payload.append(`tags[${index}]`, tag);
            });
            images.forEach((image, index) => {
                payload.append(`images[${index}]`, image);
            });

            const csrfToken = getCsrfToken();
            const response = await fetch('/hemeroteca/sources/manual', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
                },
                credentials: 'same-origin',
                body: payload,
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(typeof data?.message === 'string' ? data.message : 'No se pudo registrar la fuente manual.');
            }

            toast.success(`Fuente registrada (${data.imagesCount ?? images.length} imagenes, OCR ${data.ocrTextLength ?? 0} caracteres).`);
            closeAndReset();
            router.reload({
                only: ['sources', 'suggestedTags', 'total', 'perPage', 'currentPage', 'lastPage', 'filters'],
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : 'No se pudo registrar la fuente manual.';
            toast.error(message);
        } finally {
            setIsRunningOcr(false);
            setIsSubmitting(false);
        }
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            onClick={closeAndReset}
        >
            <div
                className="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl dark:border-slate-800 dark:bg-slate-950"
                onClick={(event) => event.stopPropagation()}
                role="dialog"
                aria-modal="true"
                aria-labelledby="manual-source-modal-title"
            >
                <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-slate-800">
                    <div className="flex items-center gap-3">
                        <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10">
                            <Camera className="h-4.5 w-4.5 text-primary" />
                        </div>
                        <div>
                            <h2 id="manual-source-modal-title" className="text-base font-bold text-foreground">
                                Registro manual con OCR
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Sube fotografias o capturas para extraer texto automaticamente.
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Si la extension de registro automatico no resulta util en un caso concreto, utiliza esta herramienta para cargar una fuente manualmente.
                            </p>
                        </div>
                    </div>
                    <Button type="button" variant="ghost" size="icon" onClick={closeAndReset} disabled={isSubmitting} aria-label="Cerrar">
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                <div className="grid min-h-0 flex-1 gap-0 md:grid-cols-[1.15fr_0.85fr]">
                    <div className="space-y-5 overflow-y-auto p-6">
                        <div className="space-y-2">
                            <Label htmlFor="manual-source-url">URL/Fuente</Label>
                            <Input
                                id="manual-source-url"
                                value={url}
                                onChange={(event) => setUrl(event.target.value)}
                                placeholder="https://sitio.com/nota"
                                className="h-10"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="manual-source-title">Titulo</Label>
                            <Input
                                id="manual-source-title"
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="Ej: Captura Facebook 2026-04-10"
                                className="h-10"
                            />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="manual-source-description">Descripcion</Label>
                            <textarea
                                id="manual-source-description"
                                value={description}
                                onChange={(event) => setDescription(event.target.value)}
                                rows={3}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                placeholder="Contexto adicional de la fuente"
                            />
                        </div>

                        <div className="flex items-start gap-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4 dark:border-slate-700 dark:bg-slate-900/40">
                            <input
                                id="manual-source-request-letter"
                                type="checkbox"
                                checked={isRequestLetter}
                                onChange={(event) => {
                                    setIsRequestLetter(event.target.checked);

                                    if (!event.target.checked) {
                                        setRequestLetterNumber('');
                                    }
                                }}
                                className="mt-1 h-4 w-4 rounded border-slate-300 text-slate-800 focus:ring-2 focus:ring-slate-500"
                            />
                            <div className="flex-1">
                                <Label htmlFor="manual-source-request-letter" className="cursor-pointer">
                                    Vinculado a oficio de peticion
                                </Label>
                                {isRequestLetter && (
                                    <Input
                                        value={requestLetterNumber}
                                        onChange={(event) => setRequestLetterNumber(event.target.value)}
                                        placeholder="Numero de oficio (ej: FGE/DIC/001/2026)"
                                        className="mt-2 h-10"
                                    />
                                )}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="manual-tag-input">Etiquetas</Label>
                            <div className="flex gap-2">
                                <div className="relative flex-1">
                                    <Input
                                        id="manual-tag-input"
                                        value={tagInput}
                                        onChange={(event) => setTagInput(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter' || (event.key === 'Tab' && tagInput.trim() !== '')) {
                                                event.preventDefault();
                                                handleAddTag();
                                            }
                                        }}
                                        onFocus={() => setIsTagInputFocused(true)}
                                        onBlur={() => {
                                            window.setTimeout(() => setIsTagInputFocused(false), 120);
                                        }}
                                        placeholder="Agregar etiqueta (Enter o Tab)"
                                    />
                                    {isTagInputFocused && filteredTagSuggestions.length > 0 ? (
                                        <div className="absolute left-0 right-0 top-[calc(100%+0.35rem)] z-20 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-600 dark:bg-slate-800">
                                            <div className="max-h-32 overflow-y-auto">
                                                {filteredTagSuggestions.map((suggestion) => (
                                                    <button
                                                        key={`manual-tag-suggestion-${suggestion}`}
                                                        type="button"
                                                        onMouseDown={(event) => {
                                                            event.preventDefault();
                                                            addTag(suggestion);
                                                            setTagInput('');
                                                            setIsTagInputFocused(true);
                                                        }}
                                                        className="block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700"
                                                    >
                                                        {suggestion}
                                                    </button>
                                                ))}
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                                <Button type="button" variant="outline" onClick={handleAddTag}>
                                    <Plus className="h-3.5 w-3.5" />
                                </Button>
                            </div>

                            <div className="flex min-h-[44px] max-h-32 flex-wrap gap-2 overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 pr-2 dark:border-slate-800 dark:bg-slate-950">
                                {tags.length > 0 ? (
                                    tags.map((tag) => (
                                        <Badge key={`manual-tag-${tag}`} variant="outline" className="gap-1 text-xs">
                                            {tag}
                                            <button type="button" onClick={() => setTags((prev) => prev.filter((item) => item !== tag))}>
                                                <X className="h-3 w-3" />
                                            </button>
                                        </Badge>
                                    ))
                                ) : (
                                    <span className="text-sm text-muted-foreground">Sin etiquetas</span>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex min-h-0 flex-col border-t border-slate-100 bg-slate-50/70 p-6 dark:border-slate-800 dark:bg-slate-900/30 md:border-l md:border-t-0">
                        <Label htmlFor="manual-images-input" className="mb-2">
                            Imagenes / capturas
                        </Label>
                        <label
                            htmlFor="manual-images-input"
                            className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-slate-300 bg-white px-4 py-3 text-sm text-slate-600 transition-colors hover:border-slate-400 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300 dark:hover:border-slate-600"
                        >
                            <UploadCloud className="h-4 w-4" />
                            Seleccionar archivos
                        </label>
                        <input
                            id="manual-images-input"
                            type="file"
                            accept="image/*"
                            multiple
                            className="hidden"
                            onChange={(event) => {
                                handleImageSelection(event.target.files);
                                event.currentTarget.value = '';
                            }}
                        />

                        <div className="mt-4 min-h-0 flex-1 space-y-2 overflow-y-auto pr-1">
                            {previewImages.length > 0 ? (
                                previewImages.map((preview, index) => (
                                    <div key={`${preview.name}-${index}`} className="rounded-lg border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-950">
                                        <div className="relative h-24 w-full overflow-hidden rounded-md bg-slate-100 dark:bg-slate-800">
                                            <img src={preview.url} alt={preview.name} className="h-full w-full object-cover" />
                                            <button
                                                type="button"
                                                onClick={() => removeImage(index)}
                                                className="absolute right-1 top-1 rounded bg-black/65 p-1 text-white"
                                                aria-label={`Quitar ${preview.name}`}
                                            >
                                                <X className="h-3 w-3" />
                                            </button>
                                        </div>
                                        <p className="mt-2 truncate text-xs text-slate-500 dark:text-slate-400">{preview.name}</p>
                                    </div>
                                ))
                            ) : (
                                <div className="rounded-xl border border-dashed border-slate-300 bg-white/70 px-3 py-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-950/60 dark:text-slate-400">
                                    Agrega una o mas imagenes para aplicar OCR.
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className="flex items-center justify-between border-t border-slate-100 px-6 py-4 dark:border-slate-800">
                    <p className="text-xs text-muted-foreground">
                        
                    </p>
                    <div className="flex items-center gap-2">
                        <Button type="button" variant="outline" onClick={closeAndReset} disabled={isSubmitting}>
                            Cancelar
                        </Button>
                        <Button type="button" onClick={() => void handleSubmit()} disabled={isSubmitting}>
                            {(isSubmitting || isRunningOcr) ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                            {isRunningOcr ? 'Procesando OCR...' : null}
                            {!isRunningOcr ? 'Guardar manual' : null}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
