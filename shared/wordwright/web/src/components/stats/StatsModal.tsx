import { MAX_GUESSES } from '@/engine';
import { formatAverage, formatDuration, formatNumber, formatPercent } from '@/lib/format';
import { useStats } from '@/state';
import { averageGuesses, averageSolveMs, winPercentage, type StatsBucket } from '@/storage';

import { Modal } from '../ui/Modal';

function StatTile({ label, value }: { label: string; value: string }): React.JSX.Element {
  return (
    <div className="flex flex-col items-center">
      <dt className="order-2 text-center text-[0.65rem] leading-tight text-text-muted">{label}</dt>
      <dd className="order-1 text-2xl font-bold tabular-nums">{value}</dd>
    </div>
  );
}

function GuessDistribution({ bucket }: { bucket: StatsBucket }): React.JSX.Element {
  const max = Math.max(1, ...bucket.distribution);

  return (
    <div className="flex flex-col gap-1">
      {Array.from({ length: MAX_GUESSES }, (_unused, index) => {
        const count = bucket.distribution[index] ?? 0;
        const widthPercent = Math.max(7, (count / max) * 100);

        return (
          <div key={index} className="flex items-center gap-2 text-sm">
            <span className="w-3 tabular-nums">{index + 1}</span>
            <div className="flex-1">
              <div
                className="flex justify-end rounded-sm bg-tile-absent px-1.5 py-0.5 text-xs font-bold text-tile-text-revealed data-[has-count=true]:bg-tile-correct"
                style={{ width: `${String(widthPercent)}%` }}
                data-has-count={count > 0}
              >
                {count}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export function StatsModal({
  isOpen,
  onClose,
}: {
  isOpen: boolean;
  onClose: () => void;
}): React.JSX.Element {
  const { stats } = useStats();
  const bucket = stats.overall;
  const hasGames = bucket.gamesPlayed > 0;

  return (
    <Modal isOpen={isOpen} title="Statistics" onClose={onClose}>
      {hasGames ? (
        <div className="flex flex-col gap-6">
          <dl className="grid grid-cols-4 gap-2">
            <StatTile label="Played" value={formatNumber(bucket.gamesPlayed)} />
            <StatTile label="Win %" value={formatPercent(winPercentage(bucket))} />
            <StatTile label="Current streak" value={formatNumber(bucket.currentStreak)} />
            <StatTile label="Best streak" value={formatNumber(bucket.bestStreak)} />
          </dl>

          <dl className="grid grid-cols-4 gap-2">
            <StatTile label="Avg guesses" value={formatAverage(averageGuesses(bucket))} />
            <StatTile label="Avg time" value={formatDuration(averageSolveMs(bucket))} />
            <StatTile label="Fastest" value={formatDuration(bucket.fastestSolveMs)} />
            <StatTile label="Score" value={formatNumber(bucket.totalScore)} />
          </dl>

          <section>
            <h3 className="mb-2 text-xs font-bold tracking-wide uppercase">Guess distribution</h3>
            <GuessDistribution bucket={bucket} />
          </section>

          {stats.recentGames.length > 0 ? (
            <section>
              <h3 className="mb-2 text-xs font-bold tracking-wide uppercase">Recent games</h3>
              <ul className="flex flex-col gap-1 text-sm">
                {stats.recentGames.slice(0, 8).map((record) => (
                  <li key={record.id} className="flex items-center justify-between gap-2">
                    <span className="font-mono tracking-wider uppercase">{record.answer}</span>
                    <span className="text-text-muted">
                      {record.won ? `${String(record.guessesUsed)}/${String(MAX_GUESSES)}` : 'X'} ·{' '}
                      {formatDuration(record.solveMs)}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}
        </div>
      ) : (
        // FR-41: never show zeros and NaN to a first-time player.
        <p className="py-6 text-center text-sm text-text-muted">
          No games yet. Finish a game and your statistics will appear here.
        </p>
      )}
    </Modal>
  );
}
