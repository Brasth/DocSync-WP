import { createElement, Fragment } from '@wordpress/element';

type JsxType = Parameters<typeof createElement>[0];
type JsxProps = Parameters<typeof createElement>[1];

export { Fragment };

export const jsx = (type: JsxType, props: JsxProps, key?: string): JSX.Element => {
  const nextProps = key === undefined ? props : { ...(props ?? {}), key };

  return createElement(type, nextProps);
};

export const jsxs = jsx;
export const jsxDEV = jsx;
