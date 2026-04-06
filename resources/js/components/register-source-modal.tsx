import { router, usePage } from '@inertiajs/react';
import { CloudUpload, Link as LinkIcon, Plus, X } from 'lucide-react';
import { useEffect, useRef, useState  } from 'react';
import type {KeyboardEvent} from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
};

type RegisterSourceModalProps = {
    prefillDraft?: PrefillDraft | null;
};

export function RegisterSourceModal({ prefillDraft = null }: RegisterSourceModalProps) {
    const { flash } = usePage<FlashProps>().props;
    const lastFlashMessageRef = useRef<string | null>(null);
    const waczFileInputRef = useRef<HTMLInputElement>(null);

    const [open, setOpen] = useState(false);
    const [url, setUrl] = useState('');
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [tagInput, setTagInput] = useState('');
    const [tags, setTags] = useState<string[]>([]);
    const [isRequestLetter, setIsRequestLetter] = useState(false);
    const [requestLetterNumber, setRequestLetterNumber] = useState('');
    const [isSaving, setIsSaving] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [waczFile, setWaczFile] = useState<File | null>(null);
    const [draftToken, setDraftToken] = useState('');
    const [draftFileName, setDraftFileName] = useState('');

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
        if (!prefillDraft) {
            return;
        }

        setOpen(true);
        setUrl(prefillDraft.url ?? '');
        setDraftToken(prefillDraft.draftToken ?? '');
        setDraftFileName(prefillDraft.waczFileName ?? 'archivo.wacz');
        setWaczFile(null);

        if (waczFileInputRef.current) {
            waczFileInputRef.current.value = '';
        }
    }, [prefillDraft]);

    const addTag = (value: string) => {
        const nextTag = value.trim();

        if (!nextTag || tags.includes(nextTag)) {
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

    const clearForm = () => {
        setUrl('');
        setName('');
        setDescription('');
        setTagInput('');
        setTags([]);
        setIsRequestLetter(false);
        setRequestLetterNumber('');
        setErrorMessage(null);
        setWaczFile(null);
        setDraftToken('');
        setDraftFileName('');

        if (waczFileInputRef.current) {
            waczFileInputRef.current.value = '';
        }
    };

    const handleCancel = () => {
        setOpen(false);
        clearForm();
    };

    const handleSave = () => {
        if (!url.trim() || !name.trim()) {
            setErrorMessage('URL y Nombre son obligatorios.');

            return;
        }

        if (isRequestLetter && !requestLetterNumber.trim()) {
            setErrorMessage('El numero de oficio es obligatorio cuando corresponde a oficio de peticion.');

            return;
        }

        if (!waczFile && !draftToken) {
            setErrorMessage('Debes seleccionar un archivo WACZ o usar uno precargado por la extension.');

            return;
        }

        setIsSaving(true);
        setErrorMessage(null);

        router.post(
            '/hemeroteca/sources',
            {
                url: url.trim(),
                name: name.trim(),
                description: description.trim() || null,
                tags,
                isRequestLetter,
                oficioNumber: requestLetterNumber.trim() || null,
                waczFile,
                draftToken: draftToken || null,
            },
            {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setOpen(false);
                    clearForm();
                },
                onError: () => {
                    setErrorMessage('No se pudo guardar la fuente. Verifique los datos e intente de nuevo.');
                    toast.error('No se pudo guardar la fuente.');
                },
                onFinish: () => {
                    setIsSaving(false);
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="h-11 gap-2 px-5 lg:w-auto">
                    <Plus className="size-4" />
                    Nueva Fuente
                </Button>
            </DialogTrigger>

            <DialogContent className="max-h-[90vh] overflow-y-auto p-0 sm:max-w-[560px]">
                <DialogHeader className="border-b px-6 pt-6 pb-4">
                    <DialogTitle className="text-3xl font-semibold tracking-tight">
                        Registrar Nueva Fuente
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Captura los datos para registrar una nueva fuente con su archivo WACZ.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-5 px-6 py-5">
                    <div className="space-y-2">
                        <Label htmlFor="source-url" className="text-sm font-semibold">
                            URL de la fuente <span className="text-destructive">*</span>
                        </Label>
                        <div className="relative">
                            <LinkIcon className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                            <Input
                                id="source-url"
                                value={url}
                                onChange={(event) => setUrl(event.target.value)}
                                placeholder="https://ejemplo.com/pagina"
                                className="h-11 pl-10"
                            />
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Ingrese la URL original del sitio o recurso archivado
                        </p>
                    </div>

                    {draftToken ? (
                        <div className="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
                            WACZ precargado desde extension: {draftFileName || 'archivo.wacz'}
                        </div>
                    ) : null}

                    <div className="space-y-2">
                        <Label htmlFor="source-name" className="text-sm font-semibold">
                            Nombre <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="source-name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="Nombre identificador de la fuente"
                            className="h-11"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-description" className="text-sm font-semibold">
                            Descripcion
                        </Label>
                        <textarea
                            id="source-description"
                            value={description}
                            onChange={(event) => setDescription(event.target.value)}
                            placeholder="Breve descripcion del contenido o relevancia de la fuente..."
                            className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 min-h-[112px] w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px]"
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-tags" className="text-sm font-semibold">
                            Etiquetas
                        </Label>

                        <div className="focus-within:border-ring focus-within:ring-ring/50 flex min-h-11 flex-wrap items-center gap-2 rounded-md border px-2 py-1.5 transition-[color,box-shadow] focus-within:ring-[3px]">
                            {tags.map((tag) => (
                                <Badge
                                    key={tag}
                                    variant="secondary"
                                    className="gap-1 rounded-full border border-indigo-200 bg-indigo-100 px-2.5 py-1 text-xs text-indigo-700"
                                >
                                    {tag}
                                    <button
                                        type="button"
                                        onClick={() => removeTag(tag)}
                                        className="rounded-full p-0.5 hover:bg-indigo-200/70"
                                        aria-label={`Eliminar etiqueta ${tag}`}
                                    >
                                        <X className="size-3" />
                                    </button>
                                </Badge>
                            ))}

                            <Input
                                id="source-tags"
                                value={tagInput}
                                onChange={(event) => setTagInput(event.target.value)}
                                onKeyDown={handleTagKeyDown}
                                placeholder="Escribir etiqueta y presionar Enter..."
                                className="h-8 min-w-[220px] flex-1 border-0 px-1 py-0 shadow-none focus-visible:ring-0"
                            />
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-wacz" className="text-sm font-semibold">
                            Archivo WACZ {draftToken ? '(opcional para reemplazar)' : <span className="text-destructive">*</span>}
                        </Label>
                        <Input
                            ref={waczFileInputRef}
                            id="source-wacz"
                            type="file"
                            accept=".wacz,.zip,application/zip,application/octet-stream"
                            className="h-11"
                            onChange={(event) => {
                                const selectedFile = event.target.files?.[0] ?? null;
                                setWaczFile(selectedFile);
                            }}
                        />
                        <p className="text-muted-foreground text-xs">
                            Sube el respaldo web en formato .wacz o .wacz.zip
                        </p>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="source-request-letter" className="text-sm font-semibold">
                            Oficio de peticion
                        </Label>
                        <label htmlFor="source-request-letter" className="flex items-center gap-2 text-sm text-foreground">
                            <input
                                id="source-request-letter"
                                type="checkbox"
                                checked={isRequestLetter}
                                onChange={(event) => {
                                    const nextValue = event.target.checked;
                                    setIsRequestLetter(nextValue);

                                    if (!nextValue) {
                                        setRequestLetterNumber('');
                                    }
                                }}
                                className="h-4 w-4 rounded border-input"
                            />
                            Marcar si corresponde a oficio de peticion
                        </label>

                        {isRequestLetter ? (
                            <div className="space-y-2 pt-1">
                                <Label htmlFor="source-request-letter-number" className="text-sm font-semibold">
                                    Numero de oficio <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="source-request-letter-number"
                                    value={requestLetterNumber}
                                    onChange={(event) => setRequestLetterNumber(event.target.value)}
                                    placeholder="Ejemplo: 123-2026"
                                    className="h-10"
                                />
                            </div>
                        ) : null}
                    </div>

                    {errorMessage ? (
                        <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {errorMessage}
                        </p>
                    ) : null}
                </div>

                <DialogFooter className="border-t px-6 py-4 sm:justify-end">
                    <DialogClose asChild>
                        <Button variant="outline" className="h-10 px-6" onClick={handleCancel}>
                            Cancelar
                        </Button>
                    </DialogClose>
                    <Button className="h-10 gap-2 px-6" onClick={handleSave} disabled={isSaving}>
                        <CloudUpload className="size-4" />
                        {isSaving ? 'Guardando...' : 'Guardar con WACZ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}