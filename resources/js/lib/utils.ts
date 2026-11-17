type ClassValue = string | false | null | undefined;

/** Une clases omitiendo las vacías o falsas: `cn('a', isActive && 'b')`. */
export function cn(...classes: ClassValue[]): string {
    return classes.filter((value): value is string => Boolean(value)).join(' ');
}
