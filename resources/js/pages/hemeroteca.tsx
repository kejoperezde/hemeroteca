import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileArchive,
    Filter,
    Grid3X3,
    List,
    Plus,
    Search,
    Upload,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as XLSX from 'xlsx-js-style';
import { SourceDetailsModal } from '@/components/source-details-modal';
import { Button } from '@/components/ui/button';
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
    hash: string | null;
    currentHash: string | null;
    hashStatus: 'valido' | 'invalido' | 'sin_hash' | 'sin_respaldo' | 'sin_verificar';
};

type Filters = {
    search: string;
    from: string;
    to: string;
    tags: string[];
    sort: 'name' | 'description' | 'capturedBy' | 'date';
    direction: 'asc' | 'desc';
    view: 'list' | 'grid';
};

type HemerotecaProps = {
    sources: Source[];
    suggestedTags: string[];
    total: number;
    perPage: number;
    currentPage: number;
    lastPage: number;
    filters: Filters;
};

type ViewMode = 'list' | 'grid';

export default function Hemeroteca({
    sources,
    suggestedTags,
    total,
    perPage,
    currentPage,
    lastPage,
    filters,
}: HemerotecaProps) {
    const fromDateRef = useRef<HTMLInputElement>(null);
    const toDateRef = useRef<HTMLInputElement>(null);
    const tagSearchInputRef = useRef<HTMLInputElement>(null);
    const searchDebounce = useRef<ReturnType<typeof setTimeout>>(undefined);

    const [viewMode, setViewMode] = useState<ViewMode>(filters.view ?? 'grid');
    const [selectedSource, setSelectedSource] = useState<Source | null>(null);

    // Local state mirrors server filter state so the UI responds immediately
    const [searchInput, setSearchInput] = useState(filters.search);
    const [fromDate, setFromDate] = useState(filters.from);
    const [toDate, setToDate] = useState(filters.to);
    const [selectedTags, setSelectedTags] = useState<string[]>(filters.tags);
    const [sortKey, setSortKey] = useState<Filters['sort']>(filters.sort);
    const [sortDirection, setSortDirection] = useState<Filters['direction']>(filters.direction);

    const [isTagSearchOpen, setIsTagSearchOpen] = useState(false);
    const [tagSearchTerm, setTagSearchTerm] = useState('');

    useEffect(() => {
        if (isTagSearchOpen) {
            tagSearchInputRef.current?.focus();
        }
    }, [isTagSearchOpen]);

    const navigate = useCallback(
        (params: Partial<Filters & { page: number }>) => {
            const merged = {
                search: searchInput,
                from: fromDate,
                to: toDate,
                tags: selectedTags,
                sort: sortKey,
                direction: sortDirection,
                view: viewMode,
                page: 1,
                ...params,
            };

            const query: Record<string, string | string[] | number> = {};

            if (merged.search) {
                query.search = merged.search;
            }

            if (merged.from) {
                query.from = merged.from;
            }

            if (merged.to) {
                query.to = merged.to;
            }

            if (merged.tags.length > 0) {
                query.tags = merged.tags;
            }

            if (merged.sort !== 'date') {
                query.sort = merged.sort;
            }

            if (merged.direction !== 'desc') {
                query.direction = merged.direction;
            }

            // Only include view in URL when it's non-default (list)
            if (merged.view === 'list') {
                query.view = merged.view;
            }

            if (merged.page > 1) {
                query.page = merged.page;
            }

            router.get(hemeroteca().url, query, {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            });
        },
        [searchInput, fromDate, toDate, selectedTags, sortKey, sortDirection, viewMode],
    );

    const handleSearchChange = (value: string) => {
        setSearchInput(value);
        clearTimeout(searchDebounce.current);
        searchDebounce.current = setTimeout(() => {
            navigate({ search: value, page: 1 });
        }, 400);
    };

    const handleFromDateChange = (value: string) => {
        setFromDate(value);
        navigate({ from: value, page: 1 });
    };

    const handleToDateChange = (value: string) => {
        setToDate(value);
        navigate({ to: value, page: 1 });
    };

    const handleSort = (key: Filters['sort']) => {
        const newDirection = sortKey === key && sortDirection === 'asc' ? 'desc' : sortKey === key ? 'asc' : 'desc';
        setSortKey(key);
        setSortDirection(newDirection);
        navigate({ sort: key, direction: newDirection, page: 1 });
    };

    const addTag = (tag: string) => {
        if (selectedTags.includes(tag)) {
            setIsTagSearchOpen(false);
            setTagSearchTerm('');

            return;
        }

        const next = [...selectedTags, tag];
        setSelectedTags(next);
        setIsTagSearchOpen(false);
        setTagSearchTerm('');
        navigate({ tags: next, page: 1 });
    };

    const removeTag = (tag: string) => {
        const next = selectedTags.filter((t) => t !== tag);
        setSelectedTags(next);
        navigate({ tags: next, page: 1 });
    };

    const clearFilters = () => {
        setSearchInput('');
        setFromDate('');
        setToDate('');
        setSelectedTags([]);
        navigate({ search: '', from: '', to: '', tags: [], page: 1 });
    };

    const hasActiveFilters =
        searchInput.trim().length > 0 ||
        fromDate.length > 0 ||
        toDate.length > 0 ||
        selectedTags.length > 0;

    const activeFilterCount = [
        searchInput.trim() !== '',
        fromDate !== '',
        toDate !== '',
        ...selectedTags.map(() => true),
    ].filter(Boolean).length;

    const openDatePicker = (input: HTMLInputElement | null) => {
        if (!input) {
            return;
        }

        input.focus();

        if ('showPicker' in input) {
            input.showPicker();
        }
    };

    const filteredSuggestedTags = useMemo(() => {
        const q = tagSearchTerm.trim().toLowerCase();

        return suggestedTags
            .map((t) => t.trim())
            .filter(Boolean)
            .filter((t) => !q || t.toLowerCase().includes(q));
    }, [suggestedTags, tagSearchTerm]);

    const getSortIcon = (key: Filters['sort']) => (
        <ChevronDown
            className={`h-3.5 w-3.5 transition-transform ${
                sortKey === key ? 'opacity-80' : 'opacity-25'
            } ${sortKey === key && sortDirection === 'asc' ? 'rotate-180' : ''}`}
        />
    );

    const handleExportToExcel = () => {
        if (sources.length === 0) {
            return;
        }

        const rows = sources.map((s) => ({
            ID: s.id,
            TITULO: s.name,
            DESCRIPCIÓN: s.description,
            URL: s.url,
            ETIQUETAS: s.tags.join(', '),
            FECHA_CAPTURA: s.date,
            CAPTURADO_POR: s.capturedBy,
            HASH: s.hash ?? '',
        }));
        const ws = XLSX.utils.json_to_sheet(rows);
        ws['!cols'] = [{ wch: 7 }, { wch: 20 }, { wch: 35 }, { wch: 13 }, { wch: 13 }, { wch: 17 }, { wch: 20 }, { wch: 13 }];

        const headerStyle = {
            font: { bold: true, color: { rgb: 'FFFFFF' } },
            fill: { fgColor: { rgb: '374151' } },
            alignment: { horizontal: 'center', vertical: 'center' },
        };

        const centerStyle = {
            alignment: { horizontal: 'center', vertical: 'center' },
        };

        const range = XLSX.utils.decode_range(ws['!ref'] ?? 'A1:H1');

        for (let c = range.s.c; c <= range.e.c; c += 1) {
            const headerAddress = XLSX.utils.encode_cell({ r: 0, c });

            if (ws[headerAddress]) {
                ws[headerAddress].s = headerStyle;
            }
        }

        for (let r = 1; r <= range.e.r; r += 1) {
            for (const c of [0, 5, 6]) {
                const address = XLSX.utils.encode_cell({ r, c });

                if (ws[address]) {
                    ws[address].s = centerStyle;
                }
            }
        }

        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Hemeroteca');
        const now = new Date();
        const ts = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`;
        XLSX.writeFile(wb, `hemeroteca_${ts}.xlsx`);
    };

    const firstItem = total === 0 ? 0 : (currentPage - 1) * perPage + 1;
    const lastItem = Math.min(currentPage * perPage, total);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Hemeroteca" />

            <div className="flex h-full w-full flex-1 flex-col">
                {/* Page header */}
                <div className="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div className="mx-auto max-w-7xl px-4 py-5 md:px-8">
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-3.5">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 ring-1 ring-primary/20">
                                    <FileArchive className="h-5 w-5 text-primary" />
                                </div>
                                <div>
                                    <h1 className="text-xl font-bold tracking-tight text-foreground">Hemeroteca</h1>
                                    <p className="text-sm text-muted-foreground">Archivo digital de fuentes capturadas</p>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-8">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
                        {/* Sidebar filters */}
                        <aside className="w-full shrink-0 lg:sticky lg:top-6 lg:w-64 xl:w-72">
                            <div className="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                                <div className="flex items-center justify-between rounded-t-xl border-b border-slate-100 bg-slate-50/50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/40">
                                    <div className="flex items-center gap-2">
                                        <Filter className="h-4 w-4 text-muted-foreground" />
                                        <span className="text-sm font-semibold text-foreground">Filtros</span>
                                        {activeFilterCount > 0 && (
                                            <span className="flex h-5 min-w-[20px] items-center justify-center rounded-full bg-primary px-1 text-[11px] font-bold text-primary-foreground">
                                                {activeFilterCount}
                                            </span>
                                        )}
                                    </div>
                                    {hasActiveFilters && (
                                        <button
                                            type="button"
                                            onClick={clearFilters}
                                            className="flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-slate-100 hover:text-foreground dark:hover:bg-slate-800"
                                        >
                                            <X className="h-3 w-3" />
                                            Limpiar
                                        </button>
                                    )}
                                </div>

                                <div className="divide-y divide-slate-100 dark:divide-slate-800">
                                    {/* Search */}
                                    <div className="p-4">
                                        <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                            Búsqueda
                                        </p>
                                        <div className="relative">
                                            <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                            <Input
                                                placeholder="Título, descripción…"
                                                className="h-9 pl-9 pr-8 text-sm"
                                                value={searchInput}
                                                onChange={(e) => handleSearchChange(e.target.value)}
                                            />
                                            {searchInput && (
                                                <button
                                                    type="button"
                                                    onClick={() => handleSearchChange('')}
                                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                    aria-label="Limpiar búsqueda"
                                                >
                                                    <X className="h-3.5 w-3.5" />
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* Date range */}
                                    <div className="p-4">
                                        <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                            Rango de fechas
                                        </p>
                                        <div className="space-y-2">
                                            <div>
                                                <p className="mb-1 text-[11px] text-muted-foreground">Desde</p>
                                                <div className="relative">
                                                    <Input
                                                        ref={fromDateRef}
                                                        type="date"
                                                        value={fromDate}
                                                        onChange={(e) => handleFromDateChange(e.target.value)}
                                                        className="h-9 w-full pr-9 text-sm [&::-webkit-calendar-picker-indicator]:pointer-events-none [&::-webkit-calendar-picker-indicator]:opacity-0"
                                                    />
                                                    <button
                                                        type="button"
                                                        aria-label="Abrir selector fecha desde"
                                                        onClick={() => openDatePicker(fromDateRef.current)}
                                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                    >
                                                        <CalendarDays className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </div>
                                            <div>
                                                <p className="mb-1 text-[11px] text-muted-foreground">Hasta</p>
                                                <div className="relative">
                                                    <Input
                                                        ref={toDateRef}
                                                        type="date"
                                                        value={toDate}
                                                        onChange={(e) => handleToDateChange(e.target.value)}
                                                        className="h-9 w-full pr-9 text-sm [&::-webkit-calendar-picker-indicator]:pointer-events-none [&::-webkit-calendar-picker-indicator]:opacity-0"
                                                    />
                                                    <button
                                                        type="button"
                                                        aria-label="Abrir selector fecha hasta"
                                                        onClick={() => openDatePicker(toDateRef.current)}
                                                        className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                                    >
                                                        <CalendarDays className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Tags */}
                                    <div className="p-4">
                                        <p className="mb-2.5 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                            Etiquetas
                                        </p>
                                        <div className="relative">
                                            {isTagSearchOpen ? (
                                                <Input
                                                    ref={tagSearchInputRef}
                                                    value={tagSearchTerm}
                                                    onChange={(e) => setTagSearchTerm(e.target.value)}
                                                    placeholder="Buscar etiqueta…"
                                                    className="h-9 text-sm"
                                                    onBlur={() => {
                                                        setTimeout(() => {
                                                            setIsTagSearchOpen(false);
                                                            setTagSearchTerm('');
                                                        }, 150);
                                                    }}
                                                />
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => setIsTagSearchOpen(true)}
                                                    className="flex h-9 w-full items-center gap-2 rounded-md border border-dashed border-slate-300 px-3 text-sm text-muted-foreground transition-colors hover:border-slate-400 hover:text-foreground dark:border-slate-600 dark:hover:border-slate-500"
                                                >
                                                    <Plus className="h-3.5 w-3.5" />
                                                    Añadir etiqueta
                                                </button>
                                            )}

                                            {isTagSearchOpen && (
                                                <div className="absolute left-0 top-full z-50 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg dark:border-slate-700 dark:bg-slate-900">
                                                    {filteredSuggestedTags.length > 0 ? (
                                                        <div className="max-h-52 overflow-y-auto p-1">
                                                            {filteredSuggestedTags.map((tag) => (
                                                                <button
                                                                    key={tag}
                                                                    type="button"
                                                                    onMouseDown={() => addTag(tag)}
                                                                    className={`flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-left text-sm transition-colors hover:bg-slate-100 dark:hover:bg-slate-800 ${
                                                                        selectedTags.includes(tag)
                                                                            ? 'font-medium text-primary'
                                                                            : 'text-foreground'
                                                                    }`}
                                                                >
                                                                    {selectedTags.includes(tag) && (
                                                                        <span className="text-[10px] text-primary">✓</span>
                                                                    )}
                                                                    {tag}
                                                                </button>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <p className="px-3 py-3 text-center text-xs text-muted-foreground">
                                                            Sin coincidencias
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </div>

                                        {selectedTags.length > 0 && (
                                            <div className="mt-3 flex flex-wrap gap-1.5">
                                                {selectedTags.map((tag) => (
                                                    <button
                                                        key={`sel-${tag}`}
                                                        type="button"
                                                        onClick={() => removeTag(tag)}
                                                        className="inline-flex items-center gap-1 rounded-md border border-primary/30 bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary transition-colors hover:bg-primary/20"
                                                    >
                                                        {tag}
                                                        <X className="h-2.5 w-2.5" />
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </aside>

                        {/* Content area */}
                        <section className="min-w-0 flex-1 space-y-4">
                            {/* Toolbar */}
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm text-muted-foreground">
                                    {total > 0 ? (
                                        <>
                                            <span className="font-semibold tabular-nums text-foreground">
                                                {firstItem}–{lastItem}
                                            </span>{' '}
                                            de{' '}
                                            <span className="font-semibold tabular-nums text-foreground">
                                                {total.toLocaleString('es-MX')}
                                            </span>{' '}
                                            resultados
                                        </>
                                    ) : (
                                        <span>Sin resultados</span>
                                    )}
                                </p>
                                <div className="flex items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-8 gap-1.5 text-xs"
                                        onClick={handleExportToExcel}
                                        disabled={sources.length === 0}
                                    >
                                        <Upload className="h-3.5 w-3.5" />
                                        Exportar
                                    </Button>
                                    <div className="flex items-center rounded-lg border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setViewMode('list');
                                                navigate({ view: 'list', page: 1 });
                                            }}
                                            aria-label="Vista lista"
                                            className={`flex h-7 w-7 items-center justify-center rounded transition-all ${
                                                viewMode === 'list'
                                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            <List className="h-3.5 w-3.5" />
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setViewMode('grid');
                                                navigate({ view: 'grid', page: 1 });
                                            }}
                                            aria-label="Vista cuadrícula"
                                            className={`flex h-7 w-7 items-center justify-center rounded transition-all ${
                                                viewMode === 'grid'
                                                    ? 'bg-primary text-primary-foreground shadow-sm'
                                                    : 'text-muted-foreground hover:text-foreground'
                                            }`}
                                        >
                                            <Grid3X3 className="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {/* List view */}
                            {viewMode === 'list' ? (
                                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950">
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b border-slate-200 bg-slate-50 dark:border-slate-800 dark:bg-slate-900">
                                                    <th className="px-4 py-3 text-left">
                                                        <span className="text-[11px] font-semibold uppercase tracking-widest text-muted-foreground">
                                                            ID
                                                        </span>
                                                    </th>
                                                    <th className="px-4 py-3 text-left">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSort('name')}
                                                            className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground hover:text-foreground"
                                                        >
                                                            Título {getSortIcon('name')}
                                                        </button>
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left md:table-cell">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSort('description')}
                                                            className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground hover:text-foreground"
                                                        >
                                                            Descripción {getSortIcon('description')}
                                                        </button>
                                                    </th>
                                                    <th className="hidden px-4 py-3 text-left xl:table-cell">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSort('capturedBy')}
                                                            className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground hover:text-foreground"
                                                        >
                                                            Analista {getSortIcon('capturedBy')}
                                                        </button>
                                                    </th>
                                                    <th className="px-4 py-3 text-left">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSort('date')}
                                                            className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-widest text-muted-foreground hover:text-foreground"
                                                        >
                                                            Fecha {getSortIcon('date')}
                                                        </button>
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-slate-800/60">
                                                {sources.map((source) => {
                                                    return (
                                                        <tr
                                                            key={source.id}
                                                            className="group cursor-pointer transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-900/40"
                                                            onClick={() => setSelectedSource(source)}
                                                        >
                                                            <td className="px-4 py-3.5 align-middle">
                                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                                                    #{source.id}
                                                                </span>
                                                            </td>
                                                            <td className="px-4 py-3.5 align-middle">
                                                                <div className="flex items-center gap-2.5">
                                                                    <div className="min-w-0">
                                                                        <p className="max-w-[200px] truncate text-sm font-semibold text-foreground transition-colors group-hover:text-primary">
                                                                            {source.name}
                                                                        </p>
                                                                        <p className="max-w-[200px] truncate text-xs text-muted-foreground">
                                                                            {source.url}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td className="hidden max-w-xs px-4 py-3.5 align-middle md:table-cell">
                                                                <p className="line-clamp-2 text-sm leading-relaxed text-muted-foreground">
                                                                    {source.description}
                                                                </p>
                                                            </td>
                                                            <td className="hidden px-4 py-3.5 align-middle text-sm text-muted-foreground xl:table-cell">
                                                                {source.capturedBy}
                                                            </td>
                                                            <td className="whitespace-nowrap px-4 py-3.5 align-middle text-sm tabular-nums text-muted-foreground">
                                                                {source.date}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                                {sources.length === 0 && (
                                                    <tr>
                                                        <td colSpan={5} className="px-4 py-20 text-center">
                                                            <div className="mx-auto flex max-w-sm flex-col items-center gap-3">
                                                                <div className="flex h-12 w-12 items-center justify-center rounded-full border-2 border-dashed border-slate-300 dark:border-slate-700">
                                                                    <Search className="h-5 w-5 text-muted-foreground" />
                                                                </div>
                                                                <div>
                                                                    <p className="font-medium text-foreground">Sin resultados</p>
                                                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                                                        No se encontraron fuentes con los filtros actuales.
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            ) : (
                                /* Grid view */
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                    {sources.map((source) => {
                                        return (
                                            <div
                                                key={source.id}
                                                className="group cursor-pointer overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition-all hover:border-slate-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-950 dark:hover:border-slate-700"
                                                onClick={() => setSelectedSource(source)}
                                            >
                                                {/* Thumbnail */}
                                                <div className="relative h-40 w-full overflow-hidden border-b border-slate-200 bg-gradient-to-br from-slate-100 to-slate-200 dark:border-slate-800 dark:from-slate-800 dark:to-slate-900">
                                                    {source.backupPath ? (
                                                        <img
                                                            src={`/hemeroteca/sources/${source.id}/backup/thumbnail`}
                                                            alt=""
                                                            className="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-105"
                                                            loading="lazy"
                                                            onError={(e) => {
                                                                (e.currentTarget as HTMLImageElement).style.display = 'none';
                                                            }}
                                                        />
                                                    ) : (
                                                        <div className="flex h-full items-center justify-center">
                                                            <FileArchive className="h-10 w-10 text-slate-300 dark:text-slate-600" />
                                                        </div>
                                                    )}
                                                    {/* ID badge */}
                                                    <div className="absolute left-2.5 top-2.5">
                                                        <span className="rounded-md bg-black/55 px-2 py-0.5 font-mono text-xs font-semibold text-white backdrop-blur-sm">
                                                            #{source.id}
                                                        </span>
                                                    </div>

                                                </div>

                                                <div className="space-y-3 p-4">
                                                    <div>
                                                        <h3 className="line-clamp-2 text-sm font-semibold leading-snug text-foreground transition-colors group-hover:text-primary">
                                                            {source.name}
                                                        </h3>
                                                        <p className="mt-1 line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                                            {source.description}
                                                        </p>
                                                    </div>
                                                    <a
                                                        href={source.url}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="block truncate rounded-md bg-slate-50 px-2.5 py-1.5 text-[11px] text-sky-600 transition-colors hover:bg-slate-100 hover:text-sky-700 dark:bg-slate-900 dark:text-sky-400 dark:hover:bg-slate-800"
                                                        onClick={(e) => e.stopPropagation()}
                                                    >
                                                        {source.url}
                                                    </a>
                                                    {source.tags.length > 0 && (
                                                        <div className="flex flex-wrap gap-1">
                                                            {source.tags.slice(0, 4).map((tag) => (
                                                                <span
                                                                    key={`${source.id}-${tag}`}
                                                                    className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                                                >
                                                                    {tag}
                                                                </span>
                                                            ))}
                                                            {source.tags.length > 4 && (
                                                                <span className="rounded-full border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] text-muted-foreground dark:border-slate-700 dark:bg-slate-800">
                                                                    +{source.tags.length - 4}
                                                                </span>
                                                            )}
                                                        </div>
                                                    )}
                                                    <div className="flex items-center justify-between border-t border-slate-100 pt-2.5 dark:border-slate-800">
                                                        <p className="text-[11px] tabular-nums text-muted-foreground">{source.date}</p>
                                                        <p className="text-[11px] text-muted-foreground">{source.capturedBy}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {sources.length === 0 && (
                                        <div className="col-span-full flex flex-col items-center gap-3 rounded-xl border border-dashed border-slate-300 bg-white py-20 text-center dark:border-slate-700 dark:bg-slate-950">
                                            <div className="flex h-12 w-12 items-center justify-center rounded-full border-2 border-dashed border-slate-300 dark:border-slate-700">
                                                <Search className="h-5 w-5 text-muted-foreground" />
                                            </div>
                                            <div>
                                                <p className="font-medium text-foreground">Sin resultados</p>
                                                <p className="mt-0.5 text-sm text-muted-foreground">
                                                    No se encontraron fuentes con los filtros actuales.
                                                </p>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* Pagination */}
                            {lastPage > 1 && (
                                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                                    <p className="text-sm text-muted-foreground">
                                        Página{' '}
                                        <span className="font-semibold text-foreground">{currentPage}</span>
                                        {' '}de{' '}
                                        <span className="font-semibold text-foreground">{lastPage}</span>
                                    </p>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="h-8 w-8"
                                            disabled={currentPage === 1}
                                            onClick={() => navigate({ page: currentPage - 1 })}
                                            aria-label="Página anterior"
                                        >
                                            <ChevronLeft className="h-4 w-4" />
                                        </Button>

                                        {Array.from({ length: Math.min(5, lastPage) }, (_, i) => {
                                            const windowSize = Math.min(5, lastPage);
                                            const half = Math.floor(windowSize / 2);
                                            let start = Math.max(1, currentPage - half);
                                            const end = Math.min(lastPage, start + windowSize - 1);
                                            start = Math.max(1, end - windowSize + 1);

                                            return start + i;
                                        }).map((p) => (
                                            <Button
                                                key={p}
                                                variant={p === currentPage ? 'default' : 'outline'}
                                                size="icon"
                                                className="h-8 w-8 text-xs"
                                                onClick={() => navigate({ page: p })}
                                            >
                                                {p}
                                            </Button>
                                        ))}

                                        <Button
                                            variant="outline"
                                            size="icon"
                                            className="h-8 w-8"
                                            disabled={currentPage === lastPage}
                                            onClick={() => navigate({ page: currentPage + 1 })}
                                            aria-label="Página siguiente"
                                        >
                                            <ChevronRight className="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>
                            )}
                        </section>
                    </div>
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
