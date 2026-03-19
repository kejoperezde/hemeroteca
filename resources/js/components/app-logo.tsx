import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-12 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-12 fill-current text-white dark:text-black" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-md">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    SIAI
                </span>
            </div>
        </>
    );
}
