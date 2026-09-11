// Canonical StatsContext presentation reads the same shared snapshot as the game.
import type { ReactNode } from 'react';
import type { StatsState } from '@/storage';
import { act, useSnapshot, storageStatus } from '../bridge';
import { StatsContext } from './gameContexts';
export interface StatsContextValue {
 readonly stats:StatsState;
 readonly resetStatistics:()=>void;
 readonly resetHistory:()=>void;
}
export function StatsProvider({children}:{children:ReactNode}) {
 const {stats}=useSnapshot();
 return <StatsContext.Provider value={{stats,resetStatistics:()=>{if(storageStatus().active)act(s=>s.resetStatistics());},resetHistory:()=>{if(storageStatus().active)act(s=>s.resetHistory());}}}>{children}</StatsContext.Provider>;
}
