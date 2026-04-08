import { Head, router, usePage } from '@inertiajs/react';
import { AlertCircle, ArrowLeft, Loader2, Save, Tag, X, ZoomIn } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';

type FlashProps = {
    flash?: {
        status?: 'success' | 'error' | null;
        message?: string | null;
    };
};

type PrefillDraft = {
    draftToken: string;
    url: string;
    waczFileName: string;
    screenshotUrl: string | null;
    previewText: string;
};

type RegisterSourceProps = {
    prefillDraft: PrefillDraft | null;
    suggestedTags?: string[];
};

export default function RegisterSource({ prefillDraft, suggestedTags = [] }: RegisterSourceProps) {
    const { flash } = usePage<FlashProps>().props;
    const lastFlashMessageRef = useRef<string | null>(null);
    const hasSubmittedRef = useRef(false);

    const [url, setUrl] = useState(prefillDraft?.url ?? '');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [tagInput, setTagInput] = useState('');
    const [tags, setTags] = useState<string[]>([]);
    const [isTagInputFocused, setIsTagInputFocused] = useState(false);
    const [isRequestLetter, setIsRequestLetter] = useState(false);
    const [requestLetterNumber, setRequestLetterNumber] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [isDiscarding, setIsDiscarding] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const draftToken = prefillDraft?.draftToken ?? '';

    const normalizedSuggestedTags = useMemo(
        () => suggestedTags.map((tag) => tag.trim()).filter(Boolean),
        [suggestedTags],
    );

    const filteredTagSuggestions = useMemo(() => {
        const search = tagInput.trim().toLowerCase();

        if (!search) {
            return [];
        }

        return normalizedSuggestedTags
            .filter((tag) => tag.toLowerCase().includes(search))
            .filter((tag) => !tags.some((selectedTag) => selectedTag.toLowerCase() === tag.toLowerCase()))
            .slice(0, 8);
    }, [normalizedSuggestedTags, tagInput, tags]);

    useEffect(() => {
        if (!flash?.message || lastFlashMessageRef.current === flash.message) {
            return;
        }

        if (flash.status === 'success') {
            toast.success(flash.message);
        } else if (flash.status === 'error') {
            toast.error(flash.message);
        }

        lastFlashMessageRef.current = flash.message;
    }, [flash]);

    useEffect(() => {
        if (!draftToken) {
            return;
        }

        const onBeforeUnload = () => {
            if (hasSubmittedRef.current) {
                return;
            }

            const payload = new FormData();
            payload.append('draftToken', draftToken);
            // Include CSRF token so Laravel doesn't reject the beacon request with 419
            const xsrf = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];

            if (xsrf) {
                payload.append('_token', decodeURIComponent(xsrf));
            }

            navigator.sendBeacon('/hemeroteca/sources/draft/discard', payload);
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    }, [draftToken]);

    const addTag = (value: string) => {
        const nextTag = value.trim().replace(/\s+/g, ' ');
        const alreadyExists = tags.some((tag) => tag.toLowerCase() === nextTag.toLowerCase());

        if (!nextTag || alreadyExists) {
            return;
        }

        setTags((prev) => [...prev, nextTag]);
    };

    const removeTag = (value: string) => {
        setTags((prev) => prev.filter((tag) => tag !== value));
    };

    const handleTagKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key !== 'Enter') {
            return;
        }

        event.preventDefault();
        addTag(tagInput);
        setTagInput('');
    };

    const handleSelectSuggestion = (tag: string) => {
        addTag(tag);
        setTagInput('');
        setIsTagInputFocused(true);
    };

    const discardDraftAndReturn = () => {
        if (!draftToken) {
            router.visit('/hemeroteca');

            return;
        }

        setIsDiscarding(true);

        const xsrfCookie = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1];
        const csrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie) : '';

        void fetch('/hemeroteca/sources/draft/discard', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(csrfToken ? { 'X-XSRF-TOKEN': csrfToken } : {}),
            },
            credentials: 'same-origin',
            body: JSON.stringify({ draftToken }),
        })
            .catch(() => {
                toast.error('No se pudo descartar el borrador.');
            })
            .finally(() => {
                setIsDiscarding(false);
                router.visit('/hemeroteca');
            });
    };

    const handleSave = () => {
        if (!draftToken) {
            setErrorMessage('No hay un borrador valido para registrar.');

            return;
        }

        if (!url.trim() || !name.trim()) {
            setErrorMessage('URL y Nombre son obligatorios.');

            return;
        }

        if (isRequestLetter && !requestLetterNumber.trim()) {
            setErrorMessage('El numero de oficio es obligatorio cuando se marca como oficio de peticion.');

            return;
        }

        const pendingTag = tagInput.trim().replace(/\s+/g, ' ');
        const mergedTags = pendingTag
            ? tags.some((tag) => tag.toLowerCase() === pendingTag.toLowerCase())
                ? tags
                : [...tags, pendingTag]
            : tags;

        if (mergedTags !== tags) {
            setTags(mergedTags);
        }

        if (pendingTag) {
            setTagInput('');
        }

        setIsSaving(true);
        setErrorMessage(null);
        hasSubmittedRef.current = true;

        router.post(
            '/hemeroteca/sources',
            {
                url: url.trim(),
                name: name.trim(),
                description: description.trim() || null,
                tags: mergedTags,
                isRequestLetter,
                oficioNumber: requestLetterNumber.trim() || null,
                draftToken,
            },
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    router.visit('/hemeroteca');
                },
                onError: () => {
                    hasSubmittedRef.current = false;
                    setErrorMessage('No se pudo guardar la fuente. Verifique los datos e intente de nuevo.');
                    toast.error('No se pudo guardar la fuente.');
                },
                onFinish: () => {
                    setIsSaving(false);
                },
            },
        );
    };

    const [isPreviewExpanded, setIsPreviewExpanded] = useState(false);

    if (!prefillDraft) {
        return (
            <AppLayout>
                <Head title="Registrar Fuente" />
                <div className="flex h-[calc(100vh-4rem)] items-center justify-center p-4">
                    <div className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-900/30">
                            <AlertCircle className="h-6 w-6 text-amber-600 dark:text-amber-400" />
                        </div>
                        <h2 className="mb-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
                            Acceso no válido
                        </h2>
                        <p className="mb-4 text-sm text-slate-600 dark:text-slate-400">
                            Inicia el registro desde la extensión del navegador.
                        </p>
                        <Button
                            onClick={() => router.visit('/hemeroteca')}
                            variant="outline"
                            className="gap-2"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Volver
                        </Button>
                    </div>
                </div>
            </AppLayout>
        );
    }

    return (
        <AppLayout>
            <Head title="Registrar Fuente" />

            {/* Modal de vista previa */}
            {isPreviewExpanded && prefillDraft.screenshotUrl && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
                    onClick={() => setIsPreviewExpanded(false)}
                >
                    <button
                        onClick={() => setIsPreviewExpanded(false)}
                        className="absolute right-4 top-4 text-white/70 hover:text-white"
                    >
                        <X className="h-6 w-6" />
                    </button>
                    <img
                        src={prefillDraft.screenshotUrl}
                        alt="Vista previa"
                        className="max-h-[90vh] max-w-[90vw] object-contain"
                    />
                </div>
            )}

            <div className="flex h-[calc(100vh-4rem)] items-center justify-center p-6">
                <div className="w-full max-w-3xl">
                    {/* Card principal */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xl shadow-slate-200/50 dark:border-slate-700 dark:bg-slate-800 dark:shadow-slate-900/50">
                        {/* Header */}
                        <div className="flex items-center justify-between border-b border-slate-100 bg-gradient-to-r from-slate-50 to-slate-100/50 px-7 py-6 dark:border-slate-700 dark:from-slate-900/80 dark:to-slate-800/50">
                            <div>
                                <h1 className="text-xl font-semibold tracking-tight text-slate-900 dark:text-white">
                                    Nueva Fuente de Información
                                </h1>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Complete los datos para registrar la fuente capturada.
                                </p>
                            </div>
                            <div className="flex items-center gap-2 rounded-full border border-[#8f7f67]/35 bg-[#8f7f67]/12 px-3.5 py-2 text-sm font-medium text-[#8f7f67] dark:border-[#8f7f67]/45 dark:bg-[#8f7f67]/20 dark:text-[#8f7f67]">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[#8f7f67]/70 opacity-75"></span>
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-[#8f7f67]"></span>
                                </span>
                                Archivos preparados
                            </div>
                        </div>

                        {/* Error */}
                        {errorMessage && (
                            <div className="mx-7 mt-5 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-400">
                                <AlertCircle className="h-5 w-5 shrink-0" />
                                <span className="flex-1">{errorMessage}</span>
                                <button onClick={() => setErrorMessage(null)} className="rounded-lg p-1.5 transition-colors hover:bg-red-100 dark:hover:bg-red-900/30">
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                        )}

                        {/* Formulario */}
                        <div className="space-y-6 p-7">
                            {/* URL y Nombre en grid */}
                            <div className="grid gap-6 sm:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="source-url" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                        URL de origen <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="source-url"
                                        value={url}
                                        onChange={(e) => setUrl(e.target.value)}
                                        placeholder="https://sitio.com/pagina"
                                        className="h-11 rounded-xl border-slate-200 bg-slate-50/50 transition-colors focus:bg-white dark:border-slate-600 dark:bg-slate-900/50"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="source-name" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Título o identificador <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="source-name"
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        placeholder="Ej: Nota periodística caso XYZ"
                                        className="h-11 rounded-xl border-slate-200 bg-slate-50/50 transition-colors focus:bg-white dark:border-slate-600 dark:bg-slate-900/50"
                                    />
                                </div>
                            </div>

                            {/* Descripción */}
                            <div className="space-y-2">
                                <Label htmlFor="source-description" className="text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Descripción
                                </Label>
                                <textarea
                                    id="source-description"
                                    value={description}
                                    onChange={(e) => setDescription(e.target.value)}
                                    placeholder="Contexto relevante sobre el contenido archivado..."
                                    rows={2}
                                    className="w-full resize-none rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm transition-colors placeholder:text-slate-400 focus:border-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-slate-400/20 dark:border-slate-600 dark:bg-slate-900/50 dark:text-white dark:focus:bg-slate-900"
                                />
                            </div>

                            {/* Etiquetas */}
                            <div className="space-y-2">
                                <Label htmlFor="source-tags" className="flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-300">
                                    <Tag className="h-4 w-4 text-slate-400" />
                                    Etiquetas de clasificación
                                </Label>
                                <div className="relative">
                                    <div className="flex min-h-[2.75rem] flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-2.5 transition-colors focus-within:border-slate-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-slate-400/20 dark:border-slate-600 dark:bg-slate-900/50 dark:focus-within:bg-slate-900">
                                        {tags.map((tag) => (
                                            <Badge key={tag} variant="secondary" className="gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1 text-sm shadow-sm dark:border-slate-600 dark:bg-slate-700">
                                                {tag}
                                                <button onClick={() => removeTag(tag)} className="rounded-full p-0.5 transition-colors hover:bg-red-100 hover:text-red-500 dark:hover:bg-red-900/30">
                                                    <X className="h-3 w-3" />
                                                </button>
                                            </Badge>
                                        ))}
                                        <input
                                            id="source-tags"
                                            value={tagInput}
                                            onChange={(e) => setTagInput(e.target.value)}
                                            onKeyDown={handleTagKeyDown}
                                            onFocus={() => setIsTagInputFocused(true)}
                                            onBlur={() => {
                                                window.setTimeout(() => {
                                                    setIsTagInputFocused(false);
                                                }, 120);
                                            }}
                                            placeholder={tags.length === 0 ? 'Agregar etiqueta + Enter' : ''}
                                            className="h-7 min-w-[140px] flex-1 border-0 bg-transparent text-sm outline-none placeholder:text-slate-400"
                                        />
                                    </div>

                                    {isTagInputFocused && filteredTagSuggestions.length > 0 ? (
                                        <div className="absolute left-0 right-0 top-[calc(100%+0.4rem)] z-10 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg dark:border-slate-600 dark:bg-slate-800">
                                            {filteredTagSuggestions.map((suggestion) => (
                                                <button
                                                    key={suggestion}
                                                    type="button"
                                                    onMouseDown={(event) => {
                                                        event.preventDefault();
                                                        handleSelectSuggestion(suggestion);
                                                    }}
                                                    className="block w-full px-4 py-2.5 text-left text-sm text-slate-700 transition-colors hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-700"
                                                >
                                                    {suggestion}
                                                </button>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                            </div>

                            {/* Oficio de petición */}
                            <div className="flex items-start gap-4 rounded-xl border border-slate-200 bg-gradient-to-r from-slate-50 to-slate-100/30 p-5 dark:border-slate-600 dark:from-slate-900/50 dark:to-slate-800/30">
                                <div className="relative flex items-center">
                                    <input
                                        id="source-request-letter"
                                        type="checkbox"
                                        checked={isRequestLetter}
                                        onChange={(e) => {
                                            setIsRequestLetter(e.target.checked);

                                            if (!e.target.checked) {
                                                setRequestLetterNumber('');
                                            }
                                        }}
                                        className="h-5 w-5 rounded-md border-slate-300 text-slate-800 transition-colors focus:ring-2 focus:ring-slate-500 focus:ring-offset-2"
                                    />
                                </div>
                                <div className="flex-1">
                                    <label htmlFor="source-request-letter" className="cursor-pointer text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Vinculado a oficio de petición
                                    </label>
                                    <div className={`overflow-hidden transition-all duration-300 ease-in-out ${isRequestLetter ? 'mt-3 max-h-20 opacity-100' : 'max-h-0 opacity-0'}`}>
                                        <Input
                                            value={requestLetterNumber}
                                            onChange={(e) => setRequestLetterNumber(e.target.value)}
                                            placeholder="Número de oficio (ej: FGE/DIC/001/2026)"
                                            className="h-11 rounded-xl border-slate-200 bg-white dark:border-slate-600 dark:bg-slate-800"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Footer con acciones */}
                        <div className="flex items-center justify-between border-t border-slate-100 bg-gradient-to-r from-slate-50 to-slate-100/50 px-7 py-5 dark:border-slate-700 dark:from-slate-900/80 dark:to-slate-800/50">
                            {/* Captura de pantalla */}
                            {prefillDraft.screenshotUrl ? (
                                <button
                                    onClick={() => setIsPreviewExpanded(true)}
                                    className="group flex items-center gap-4 rounded-xl border border-slate-200 bg-white p-2.5 pr-5 text-sm text-slate-600 shadow-sm transition-all hover:border-slate-300 hover:shadow-md dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300 dark:hover:border-slate-500"
                                >
                                    <div className="relative h-14 w-20 shrink-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-100 shadow-inner dark:border-slate-600">
                                        <img
                                            src={prefillDraft.screenshotUrl}
                                            alt="Vista previa"
                                            className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-110"
                                        />
                                        <div className="absolute inset-0 bg-black/0 transition-colors group-hover:bg-black/20" />
                                    </div>
                                    <div className="flex items-center gap-2 font-medium">
                                        <ZoomIn className="h-4 w-4" />
                                        Ver captura
                                    </div>
                                </button>
                            ) : (
                                <div />
                            )}
                            <div className="flex items-center gap-3">
                                <Button
                                    variant="outline"
                                    onClick={discardDraftAndReturn}
                                    disabled={isSaving || isDiscarding}
                                    className="h-11 gap-2 rounded-xl border-slate-200 px-6 font-medium transition-all hover:bg-slate-100 dark:border-slate-600 dark:hover:bg-slate-700"
                                >
                                    {isDiscarding ? <Loader2 className="h-4 w-4 animate-spin" /> : <X className="h-4 w-4" />}
                                    Descartar
                                </Button>
                                <Button
                                    onClick={handleSave}
                                    disabled={isSaving || isDiscarding}
                                    className="h-11 gap-2 rounded-xl bg-slate-900 px-7 font-medium shadow-lg shadow-slate-900/25 transition-all hover:bg-slate-800 hover:shadow-xl dark:bg-slate-100 dark:text-slate-900 dark:shadow-slate-100/10 dark:hover:bg-slate-200"
                                >
                                    {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                                    Guardar Fuente
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
