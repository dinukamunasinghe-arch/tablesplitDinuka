import { useState } from 'react';
import BillBuilder from './components/BillBuilder';
import KitchenQueue from './components/KitchenQueue';
import SplitSummary from './components/SplitSummary';

export default function App() {
  const [bill, setBill] = useState(null);

  return (
    <div className="page">
      <header>
        <p className="brand">TableSplit</p>
        <h1>Restaurant desk</h1>
      </header>
      <main className="workspace">
        <BillBuilder onCalculated={setBill} />
        <SplitSummary bill={bill} />
        <KitchenQueue />
      </main>
    </div>
  );
}
