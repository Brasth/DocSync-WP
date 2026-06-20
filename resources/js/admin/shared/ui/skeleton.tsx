import { createElement, Fragment } from '@wordpress/element';

type SkeletonTextProps = {
  className?: string;
  width?: string;
};

type SkeletonTableRowsProps = {
  columns: number | string[];
  rows?: number;
};

const widthsFromColumns = (columns: number | string[]): string[] => {
  if (Array.isArray(columns)) {
    return columns;
  }

  return Array.from({ length: columns }, (_, index) => `${Math.max(38, 72 - index * 8)}%`);
};

export const SkeletonText = ({ className = '', width = '100%' }: SkeletonTextProps): JSX.Element => {
  return (
    <span
      aria-hidden="true"
      className={`docsync-wp-skeleton ${className}`.trim()}
      style={{ width }}
    />
  );
};

export const SkeletonTableRows = ({ columns, rows = 4 }: SkeletonTableRowsProps): JSX.Element => {
  const widths = widthsFromColumns(columns);

  return (
    <>
      {Array.from({ length: rows }, (_, rowIndex) => (
        <tr aria-hidden="true" className="docsync-wp-skeleton-row" key={rowIndex}>
          {widths.map((width, columnIndex) => (
            <td key={`${rowIndex}-${columnIndex}`}>
              <SkeletonText width={width} />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
};
