import type { Family } from '../catalog';
import { renderTableauSolitaire } from './tableau';
import { renderPyramid } from './pyramid';
import { renderSpider } from './spider';
import { renderGeneric } from './generic';

export { renderSpiderColumn } from './spider';

export function renderTable(gameId: string, family: Family, state: unknown, humanSeat: number): string[] {
  switch (family) {
    case 'tableau-solitaire':
      return renderTableauSolitaire(gameId, state);
    case 'pyramid-solitaire':
      return renderPyramid(state);
    case 'spider-solitaire':
      return renderSpider(state);
    case 'trick-taking':
    case 'capture':
    case 'shedding':
    case 'meld':
    case 'special':
      return renderGeneric(state, humanSeat);
    default:
      return renderGeneric(state, humanSeat);
  }
}
