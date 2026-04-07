import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Grid3X3,
    List,
    Search,
    Upload,
    Plus,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import * as XLSX from 'xlsx';
import { SourceDetailsModal } from '@/components/source-details-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { hemeroteca } from '@/routes';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Hemeroteca',
        href: hemeroteca(),
    },
];

type Source = {
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

type HemerotecaProps = {
    sources?: Source[];
    suggestedTags?: string[];
};

type SortKey = 'name' | 'description' | 'tags' | 'capturedBy' | 'date';
type SortDirection = 'asc' | 'desc';
type ViewMode = 'list' | 'grid';

export default function Hemeroteca({ sources = [], suggestedTags = [] }: HemerotecaProps) {
    const fromDateRef = useRef<HTMLInputElement>(null);
    const toDateRef = useRef<HTMLInputElement>(null);
    const tagSearchInputRef = useRef<HTMLInputElement>(null);
    const normalizedSuggestedTags = useMemo(
        () => suggestedTags.map((tag) => tag.trim()).filter(Boolean),
        [suggestedTags],
    );
    const [searchTerm, setSearchTerm] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [selectedTags, setSelectedTags] = useState<string[]>([]);
    const [isTagSearchOpen, setIsTagSearchOpen] = useState(false);
    const [tagSearchTerm, setTagSearchTerm] = useState('');
    const [sortKey, setSortKey] = useState<SortKey>('date');
    const [sortDirection, setSortDirection] = useState<SortDirection>('desc');
    const [requestedPage, setRequestedPage] = useState(1);
    const [viewMode, setViewMode] = useState<ViewMode>('list');
    const [selectedSource, setSelectedSource] = useState<Source | null>(null);
    const pageSize = viewMode === 'grid' ? 9 : 8;

    const filteredSuggestedTags = useMemo(() => {
        const search = tagSearchTerm.trim().toLowerCase();

        if (!search) {
            return normalizedSuggestedTags;
        }

        return normalizedSuggestedTags.filter((tag) => tag.toLowerCase().includes(search));
    }, [normalizedSuggestedTags, tagSearchTerm]);

    useEffect(() => {
        if (isTagSearchOpen) {
            tagSearchInputRef.current?.focus();
        }
    }, [isTagSearchOpen]);

    const filteredSources = useMemo(() => {
        const search = searchTerm.trim().toLowerCase();

        return sources.filter((source) => {
            if (search) {
                const searchableText = [source.name, source.description, ...source.tags]
                    .join(' ')
                    .toLowerCase();

                if (!searchableText.includes(search)) {
                    return false;
                }
            }

            if (fromDate && (!source.capturedAt || source.capturedAt < fromDate)) {
                return false;
            }

            if (toDate && (!source.capturedAt || source.capturedAt > toDate)) {
                return false;
            }

            if (selectedTags.length > 0) {
                const sourceTagsLower = source.tags.map((tag) => tag.toLowerCase());
                const hasEverySelectedTag = selectedTags.every((tag) =>
                    sourceTagsLower.includes(tag.toLowerCase()),
                );

                if (!hasEverySelectedTag) {
                    return false;
                }
            }

            return true;
        });
    }, [sources, searchTerm, fromDate, toDate, selectedTags]);

    const sortedSources = useMemo(() => {
        const items = [...filteredSources];

        items.sort((a, b) => {
            const valueA =
                sortKey === 'tags'
                    ? a.tags.join(', ')
                    : sortKey === 'date'
                      ? a.capturedAt ?? a.date
                      : a[sortKey];

            const valueB =
                sortKey === 'tags'
                    ? b.tags.join(', ')
                    : sortKey === 'date'
                      ? b.capturedAt ?? b.date
                      : b[sortKey];

            const comparison = String(valueA).localeCompare(String(valueB), 'es', {
                numeric: true,
                sensitivity: 'base',
            });

            return sortDirection === 'asc' ? comparison : -comparison;
        });

        return items;
    }, [filteredSources, sortDirection, sortKey]);

    const totalPages = useMemo(
        () => Math.max(1, Math.ceil(sortedSources.length / pageSize)),
        [sortedSources.length, pageSize],
    );

    const currentPage = Math.min(requestedPage, totalPages);

    const displayedSources = useMemo(() => {
        const startIndex = (currentPage - 1) * pageSize;

        return sortedSources.slice(startIndex, startIndex + pageSize);
    }, [sortedSources, currentPage, pageSize]);

    const isFirstPage = currentPage === 1;
    const isLastPage = currentPage === totalPages;
    const firstItemIndex = filteredSources.length === 0 ? 0 : (currentPage - 1) * pageSize + 1;
    const lastItemIndex = filteredSources.length === 0 ? 0 : Math.min(currentPage * pageSize, filteredSources.length);

    const handleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDirection((prevDirection) => (prevDirection === 'asc' ? 'desc' : 'asc'));
            setRequestedPage(1);

            return;
        }

        setSortKey(key);
        setSortDirection('asc');
        setRequestedPage(1);
    };

    const toggleTag = (tag: string) => {
        setRequestedPage(1);
        setSelectedTags((prevTags) =>
            prevTags.includes(tag) ? prevTags.filter((item) => item !== tag) : [...prevTags, tag],
        );
    };

    const selectTag = (tag: string) => {
        setRequestedPage(1);
        setSelectedTags((prevTags) => (prevTags.includes(tag) ? prevTags : [...prevTags, tag]));
        setIsTagSearchOpen(false);
        setTagSearchTerm('');
    };

    const toggleTagSearch = () => {
        setIsTagSearchOpen((isOpen) => {
            if (isOpen) {
                setTagSearchTerm('');
            }

            return !isOpen;
        });
    };

    const clearFilters = () => {
        setSearchTerm('');
        setFromDate('');
        setToDate('');
        setSelectedTags([]);
        setRequestedPage(1);
    };

    const hasActiveFilters =
        searchTerm.trim().length > 0 || fromDate.length > 0 || toDate.length > 0 || selectedTags.length > 0;

    const openDatePicker = (input: HTMLInputElement | null) => {
        if (!input) {
            return;
        }

        input.focus();

        if ('showPicker' in input) {
            input.showPicker();
        }
    };

    const getSortIconClassName = (key: SortKey) =>
        `h-3.5 w-3.5 transition-transform ${
            sortKey === key ? 'opacity-100' : 'opacity-30'
        } ${sortKey === key && sortDirection === 'desc' ? 'rotate-180' : ''}`;

    const handleExportToExcel = () => {
        if (sortedSources.length === 0) {
            return;
        }

        const rows = sortedSources.map((source) => ({
            TITULO: source.name,
            DESCRIPCIÓN: source.description,
            URL: source.url,
            FECHA_CAPTURA: source.date,
            CAPTURADO_POR: source.capturedBy,
        }));

        const worksheet = XLSX.utils.json_to_sheet(rows);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, 'Hemeroteca');

        const now = new Date();
        const timestamp = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(
            now.getDate(),
        ).padStart(2, '0')}_${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}`;

        XLSX.writeFile(workbook, `resultados${timestamp}.xlsx`);
    };

    const handleOpenSourceDetails = (source: Source) => {
        setSelectedSource(source);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hemeroteca" />
            <div className="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
                <header className="flex flex-col gap-4 border-b pb-6 md:flex-row md:items-end md:justify-between">
                    <div className="space-y-1.5">
                        <h1 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">
                            Hemeroteca
                        </h1>
                        <p className="text-base text-muted-foreground">
                            Archivo digital
                        </p>
                    </div>
                </header>

                <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                    {/* Fixed Sidebar for Filters / Desktop */}
                    <Card className="w-full shrink-0 border-slate-200 bg-slate-50/50 shadow-sm dark:border-slate-800 dark:bg-slate-900/50 lg:w-72">
                        <CardContent className="px-5 pb-5 pt-0 space-y-6">
                            <div className="flex items-center justify-between">
                                <h2 className="text-sm font-semibold tracking-tight text-foreground">Filtros</h2>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-auto px-2 py-1 text-xs text-muted-foreground hover:text-foreground"
                                    onClick={clearFilters}
                                    disabled={!hasActiveFilters}
                                >
                                    Limpiar
                                </Button>
                            </div>

                            <div className="space-y-2">
                                <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Buscar
                                </label>
                                <div className="relative w-full">
                                    <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                        <Search className="h-4 w-4 text-muted-foreground" />
                                    </div>
                                    <Input
                                        placeholder="Nombre o contenido..."
                                        className="h-9 w-full border-slate-200 bg-white pl-9 text-sm dark:border-slate-800 dark:bg-slate-950"
                                        value={searchTerm}
                                        onChange={(event) => {
                                            setSearchTerm(event.target.value);
                                            setRequestedPage(1);
                                        }}
                                    />
                                </div>
                            </div>
                            
                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Fecha desde
                                    </label>
                                    <div className="relative">
                                        <Input
                                            ref={fromDateRef}
                                            type="date"
                                            value={fromDate}
                                            onChange={(event) => {
                                                setFromDate(event.target.value);
                                                setRequestedPage(1);
                                            }}
                                            className="h-9 w-full pr-10 text-sm [&::-webkit-calendar-picker-indicator]:pointer-events-none [&::-webkit-calendar-picker-indicator]:opacity-0"
                                        />
                                        <button
                                            type="button"
                                            aria-label="Abrir selector de fecha desde"
                                            onClick={() => openDatePicker(fromDateRef.current)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        >
                                            <CalendarDays className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Fecha hasta
                                    </label>
                                    <div className="relative">
                                        <Input
                                            ref={toDateRef}
                                            type="date"
                                            value={toDate}
                                            onChange={(event) => {
                                                setToDate(event.target.value);
                                                setRequestedPage(1);
                                            }}
                                            className="h-9 w-full pr-10 text-sm [&::-webkit-calendar-picker-indicator]:pointer-events-none [&::-webkit-calendar-picker-indicator]:opacity-0"
                                        />
                                        <button
                                            type="button"
                                            aria-label="Abrir selector de fecha hasta"
                                            onClick={() => openDatePicker(toDateRef.current)}
                                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                        >
                                            <CalendarDays className="h-4 w-4" />
                                        </button>
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <label className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                        Etiquetas
                                    </label>
                                    
                                    <div className="flex flex-col gap-2">
                                        <div className="relative w-full">
                                            {isTagSearchOpen ? (
                                                <Input
                                                    ref={tagSearchInputRef}
                                                    value={tagSearchTerm}
                                                    onChange={(event) => setTagSearchTerm(event.target.value)}
                                                    placeholder="Buscar etiqueta..."
                                                    className="h-9 w-full text-sm"
                                                    autoFocus
                                                />
                                            ) : (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="w-full justify-start text-muted-foreground shadow-sm"
                                                    type="button"
                                                    onClick={toggleTagSearch}
                                                    aria-expanded={isTagSearchOpen}
                                                >
                                                    <Plus className="mr-2 h-4 w-4" />
                                                    Añadir etiqueta
                                                </Button>
                                            )}

                                            {isTagSearchOpen && tagSearchTerm.trim().length > 0 && (
                                                <div className="absolute left-0 top-full z-50 mt-1 w-full rounded-md border bg-white dark:bg-slate-950 shadow-md outline-none animate-in fade-in-0 zoom-in-95">
                                                    {filteredSuggestedTags.length > 0 ? (
                                                        <div className="max-h-48 space-y-1 overflow-y-auto p-1">
                                                            {filteredSuggestedTags.map((tag) => (
                                                                <Button
                                                                    key={tag}
                                                                    type="button"
                                                                    size="sm"
                                                                    variant={selectedTags.includes(tag) ? 'secondary' : 'ghost'}
                                                                    onClick={() => selectTag(tag)}
                                                                    className="w-full justify-start rounded-sm text-sm font-normal"
                                                                >
                                                                    {tag}
                                                                </Button>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <p className="px-3 py-3 text-center text-xs text-muted-foreground">
                                                            No se encontraron etiquetas.
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {selectedTags.length > 0 && (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {selectedTags.map((tag) => (
                                                    <Badge
                                                        key={`selected-${tag}`}
                                                        variant="secondary"
                                                        className="group cursor-pointer rounded-md bg-secondary text-secondary-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
                                                        onClick={() => toggleTag(tag)}
                                                    >
                                                        {tag}
                                                        <span className="ml-1 sr-only">Quitar</span>
                                                    </Badge>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <section className="flex-1 space-y-4 min-w-0">
                        <div className="flex justify-end rounded-lg border bg-slate-50/50 p-2 dark:bg-slate-900/50">
                            <div className="flex items-center gap-1.5">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="h-8 gap-2 text-slate-600 dark:text-slate-400"
                                    onClick={handleExportToExcel}
                                    disabled={sortedSources.length === 0}
                                >
                                    <Upload className="h-4 w-4" />
                                    Exportar
                                </Button>
                                <div className="ml-2 flex items-center rounded-md border p-0.5 shadow-sm">
                                    <Button
                                        variant={viewMode === 'grid' ? 'secondary' : 'ghost'}
                                        size="icon"
                                        className="h-7 w-7 rounded-sm text-muted-foreground"
                                        onClick={() => setViewMode('grid')}
                                        aria-label="Vista en cuadrícula"
                                    >
                                        <Grid3X3 className="h-4 w-4" />
                                    </Button>
                                    <Button
                                        variant={viewMode === 'list' ? 'secondary' : 'ghost'}
                                        size="icon"
                                        className="h-7 w-7 rounded-sm bg-background shadow-sm text-foreground"
                                        onClick={() => setViewMode('list')}
                                        aria-label="Vista en lista"
                                    >
                                        <List className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>

                        {viewMode === 'list' ? (
                            <Card className="overflow-hidden border-slate-200 py-0 shadow-sm dark:border-slate-800">
                                <div className="overflow-x-auto">
                                    <table className="w-full table-fixed text-sm">
                                        <thead className="bg-slate-50/50 dark:bg-slate-900/50">
                                            <tr className="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                                                <th className="h-10 w-[26%] px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSort('name')}
                                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                                    >
                                                        Nombre
                                                        <ChevronDown className={getSortIconClassName('name')} />
                                                    </button>
                                                </th>
                                                <th className="h-10 w-[40%] px-4 text-left align-middle font-medium text-muted-foreground">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSort('description')}
                                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                                    >
                                                        Descripcion
                                                        <ChevronDown className={getSortIconClassName('description')} />
                                                    </button>
                                                </th>
                                                <th className="h-10 w-[18%] px-4 text-left align-middle font-medium text-muted-foreground">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSort('capturedBy')}
                                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                                    >
                                                        Capturado por
                                                        <ChevronDown className={getSortIconClassName('capturedBy')} />
                                                    </button>
                                                </th>
                                                <th className="h-10 w-[16%] px-4 text-left align-middle font-medium text-muted-foreground whitespace-nowrap">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleSort('date')}
                                                        className="inline-flex items-center gap-1 hover:text-foreground"
                                                    >
                                                        Fecha Captura
                                                        <ChevronDown className={getSortIconClassName('date')} />
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="[&_tr:last-child]:border-0">
                                            {displayedSources.map((source) => (
                                                <tr
                                                    key={source.id}
                                                    className="cursor-pointer border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted"
                                                    onClick={() => handleOpenSourceDetails(source)}
                                                >
                                                    <td className="p-4 align-top">
                                                        <span className="block truncate font-semibold text-foreground">{source.name}</span>
                                                    </td>
                                                    <td className="p-4 align-top text-muted-foreground">
                                                        <p className="line-clamp-2 leading-relaxed">{source.description}</p>
                                                    </td>
                                                    <td className="p-4 align-top text-muted-foreground">
                                                        {source.capturedBy}
                                                    </td>
                                                    <td className="p-4 align-top text-muted-foreground whitespace-nowrap">
                                                        {source.date}
                                                    </td>
                                                </tr>
                                            ))}
                                            {displayedSources.length === 0 ? (
                                                <tr>
                                                    <td colSpan={4} className="h-32 text-center text-muted-foreground">
                                                        No se encontraron resultados para los filtros actuales.
                                                    </td>
                                                </tr>
                                            ) : null}
                                        </tbody>
                                    </table>
                                </div>
                            </Card>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                {displayedSources.map((source) => (
                                    <Card
                                        key={source.id}
                                        className="cursor-pointer border-slate-200 shadow-sm transition hover:border-slate-300 hover:shadow-md dark:border-slate-800"
                                        onClick={() => handleOpenSourceDetails(source)}
                                    >
                                        <CardContent className="space-y-3 p-4">
                                            <h3 className="line-clamp-2 text-sm font-semibold text-foreground">{source.name}</h3>
                                            <p className="line-clamp-3 text-sm text-muted-foreground">{source.description}</p>
                                            <a
                                                href={source.url}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="block truncate text-xs text-sky-700 underline underline-offset-2 dark:text-sky-400"
                                                onClick={(event) => event.stopPropagation()}
                                            >
                                                {source.url}
                                            </a>
                                            <div className="flex flex-wrap gap-1.5">
                                                {source.tags.length > 0 ? (
                                                    source.tags.map((tag) => (
                                                        <Badge
                                                            key={`${source.id}-${tag}`}
                                                            variant="outline"
                                                            className="bg-white font-normal text-slate-600 dark:bg-slate-950 dark:text-slate-300"
                                                        >
                                                            {tag}
                                                        </Badge>
                                                    ))
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">Sin etiquetas</span>
                                                )}
                                            </div>
                                            <p className="text-xs text-muted-foreground">{source.date}</p>
                                        </CardContent>
                                    </Card>
                                ))}
                                {displayedSources.length === 0 ? (
                                    <Card className="sm:col-span-2 xl:col-span-3">
                                        <CardContent className="py-10 text-center text-sm text-muted-foreground">
                                            No se encontraron resultados para los filtros actuales.
                                        </CardContent>
                                    </Card>
                                ) : null}
                            </div>
                        )}

                        <div className="flex items-center justify-between pt-4">
                            <p className="text-sm text-muted-foreground">
                                {filteredSources.length > 0
                                    ? `Mostrando ${firstItemIndex}-${lastItemIndex} de ${filteredSources.length} resultados`
                                    : 'Sin resultados'}
                            </p>
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="h-8 w-8"
                                    onClick={() => setRequestedPage((prevPage) => Math.max(1, prevPage - 1))}
                                    disabled={isFirstPage}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                </Button>
                                <Button variant="outline" size="sm" className="h-8 min-w-8 bg-muted text-foreground" disabled>
                                    {currentPage}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    className="h-8 w-8"
                                    onClick={() => setRequestedPage((prevPage) => Math.min(totalPages, prevPage + 1))}
                                    disabled={isLastPage}
                                >
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <SourceDetailsModal
                source={selectedSource}
                open={selectedSource !== null}
                onClose={() => setSelectedSource(null)}
            />
        </AppLayout>
    );
}
