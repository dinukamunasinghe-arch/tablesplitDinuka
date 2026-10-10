function formatLkr(value) {
  const text = String(value);
  const negative = text.startsWith('-');
  const unsigned = negative ? text.slice(1) : text;
  const [whole, fraction = ''] = unsigned.split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const hasFraction = fraction !== '' && !/^0+$/.test(fraction);
  const shown = hasFraction ? `${grouped}.${fraction}` : grouped;

  return `${negative ? '-' : ''}LKR ${shown}`;
}

function hasDiscount(amount) {
  return Number(amount) > 0;
}

export default function SplitSummary({ bill }) {
  if (!bill) {
    return (
      <section className="card">
        <h2>Bill summary</h2>
       
      </section>
    );
  }

  const { result, items } = bill;
  const summary = result.bill;

  return (
    <section className="card">
      <h2>Bill summary</h2>

      {result.seats.map((seat) => {
        const seatItems = items.filter((item) => item.shared_by.includes(seat.seat));

        return (
          <article className="seat" key={seat.seat}>
            <h3>Seat {seat.seat}</h3>
            {seatItems.length === 0 ? (
              <p className="muted">No items</p>
            ) : (
              <ul className="item-names">
                {seatItems.map((item, index) => (
                  <li key={`${item.name}-${index}`}>
                    {item.name} × {item.quantity}
                  </li>
                ))}
              </ul>
            )}
            <dl>
              <div>
                <dt>Subtotal</dt>
                <dd>{formatLkr(seat.subtotal)}</dd>
              </div>
              <div>
                <dt>Service charge</dt>
                <dd>{formatLkr(seat.service_charge)}</dd>
              </div>
              <div>
                <dt>VAT</dt>
                <dd>{formatLkr(seat.vat)}</dd>
              </div>
              {hasDiscount(seat.discount) ? (
                <div>
                  <dt>Discount</dt>
                  <dd>{formatLkr(seat.discount)}</dd>
                </div>
              ) : null}
            </dl>
            <p className="due">
              Amount to pay <strong>{formatLkr(seat.amount_due)}</strong>
            </p>
          </article>
        );
      })}

      <article className="totals">
        <h3>Whole bill</h3>
        <dl>
          <div>
            <dt>Subtotal</dt>
            <dd>{formatLkr(summary.subtotal)}</dd>
          </div>
          <div>
            <dt>Discount{summary.discount_code ? ` (${summary.discount_code})` : ''}</dt>
            <dd>{formatLkr(summary.discount)}</dd>
          </div>
          <div>
            <dt>Taxes</dt>
            <dd>{formatLkr(summary.taxes)}</dd>
          </div>
          <div>
            <dt>Total</dt>
            <dd>{formatLkr(summary.total)}</dd>
          </div>
        </dl>
      </article>
    </section>
  );
}
