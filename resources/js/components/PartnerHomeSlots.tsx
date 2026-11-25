import { createContext, useContext, type ReactNode } from 'react';

/**
 * What the Shell hands the dealer and sub-dealer home page: the menu as big tiles and the owner's notice.
 * The home page places them under the person's card and money, so a phone opens on "who I am" first (client, 2026-11-25).
 */
export type PartnerHomeSlots = { tiles: ReactNode; notice: ReactNode };

export const PartnerHomeSlotsContext = createContext<PartnerHomeSlots | null>(null);

export function usePartnerHomeSlots(): PartnerHomeSlots | null {
    return useContext(PartnerHomeSlotsContext);
}
