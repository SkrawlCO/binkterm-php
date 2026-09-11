// Browser-only rendering compatibility for the unchanged upstream Tile.
import React from 'react';
const flatten = s => Object.assign({}, ...[s].flat(Infinity).filter(Boolean));
export function View({style, children}) { return <div style={{display:'flex',flexDirection:'column',boxSizing:'border-box',borderStyle:'solid',borderWidth:0,...flatten(style)}}>{children}</div>; }
export function Text({style,children}) { return <span style={flatten(style)}>{children}</span>; }
export const Animated = {View};
export const StyleSheet = {create: s => s};
export const Platform = {OS:'web'};
// Snapshots are detached: React parent renders replace MobX observation here.
export const observer = component => component;
// Same light palette as op-design/colors.ts: RGB brighten(index * 10).
export const useColors = () => ({primary:Array.from({length:10},(_,i)=>`rgb(${[23,21,32].map(c=>Math.min(255,c+Math.round(255*i/10))).join(',')})`)});
