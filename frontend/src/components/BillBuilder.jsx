import { useState } from 'react';
import { readError, splitBill, validationLines } from '../services/api';

const emptyDraft = {
  name: '',
  unitPrice: '',
  quantity: '1',
  category: 'food',
  sharedBy: [],
};

export default function BillBuilder({ onCalculated }) {
  const [seats, setSeats] = useState([1, 2]);
  const [nextSeat, setNextSeat] = useState(3);
  const [items, setItems] = useState([]);
  const [draft, setDraft] = useState(emptyDraft);
  const [editingIndex, setEditingIndex] = useState(null);
  const [code, setCode] = useState('');
  const [loading, setLoading] = useState(false);
  const [validation, setValidation] = useState(null);
  const [failure, setFailure] = useState('');
  const [formMessage, setFormMessage] = useState('');

  function addSeat() {
    setSeats((current) => [...current, nextSeat]);
    setNextSeat((current) => current + 1);
  }

  function removeSeat(seatId) {
    setSeats((current) => current.filter((id) => id !== seatId));
    setItems((current) =>
      current.map((item) => ({
        ...item,
        sharedBy: item.sharedBy.filter((id) => id !== seatId),
      })),
    );
    setDraft((current) => ({
      ...current,
      sharedBy: current.sharedBy.filter((id) => id !== seatId),
    }));
  }

  function updateDraft(field, value) {
    setDraft((current) => ({ ...current, [field]: value }));
  }

  function toggleSharedSeat(seatId) {
    setDraft((current) => {
      const selected = current.sharedBy.includes(seatId)
        ? current.sharedBy.filter((id) => id !== seatId)
        : [...current.sharedBy, seatId];

      return { ...current, sharedBy: selected };
    });
  }

  function saveItem(event) {
    event.preventDefault();
    setFormMessage('');

    if (draft.sharedBy.length === 0) {
      setFormMessage('Choose at least one seat for this item.');
      return;
    }

    const item = {
      name: draft.name.trim(),
      unitPrice: draft.unitPrice,
      quantity: draft.quantity,
      category: draft.category,
      sharedBy: [...draft.sharedBy].sort((left, right) => left - right),
    };

    if (editingIndex === null) {
      setItems((current) => [...current, item]);
    } else {
      setItems((current) =>
        current.map((existing, index) => (index === editingIndex ? item : existing)),
      );
    }

    setDraft(emptyDraft);
    setEditingIndex(null);
  }

  function editItem(index) {
    const item = items[index];
    setDraft({
      name: item.name,
      unitPrice: item.unitPrice,
      quantity: item.quantity,
      category: item.category,
      sharedBy: [...item.sharedBy],
    });
    setEditingIndex(index);
    setFormMessage('');
  }

  function removeItem(index) {
    setItems((current) => current.filter((_, itemIndex) => itemIndex !== index));

    if (editingIndex === index) {
      setDraft(emptyDraft);
      setEditingIndex(null);
    } else if (editingIndex !== null && index < editingIndex) {
      setEditingIndex((current) => current - 1);
    }
  }

  function cancelEdit() {
    setDraft(emptyDraft);
    setEditingIndex(null);
    setFormMessage('');
  }

  async function calculateBill(event) {
    event.preventDefault();
    setLoading(true);
    setValidation(null);
    setFailure('');

    const payload = {
      seats,
      items: items.map((item) => ({
        name: item.name,
        unit_price: Number(item.unitPrice),
        quantity: Number(item.quantity),
        category: item.category,
        shared_by: item.sharedBy,
      })),
      code: code.trim() === '' ? null : code.trim(),
    };

    try {
      const result = await splitBill(payload);
      onCalculated({ result, items: payload.items });
    } catch (error) {
      const apiError = readError(error);
      setValidation(apiError.validation);
      setFailure(apiError.message);
      onCalculated(null);
    } finally {
      setLoading(false);
    }
  }

  const messages = validationLines(validation);

  return (
    <section className="card">
      <h2>Bill</h2>

      <div className="block">
        <div className="row-between">
          <h3>Seats</h3>
          <button type="button" onClick={addSeat}>
            Add seat
          </button>
        </div>
        {seats.length === 0 ? (
          <p className="muted">Add a seat before sharing items.</p>
        ) : (
          <ul className="chips">
            {seats.map((seatId) => (
              <li key={seatId}>
                Seat {seatId}
                <button type="button" className="text-button" onClick={() => removeSeat(seatId)}>
                  Remove
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      <form className="stack" onSubmit={saveItem}>
        <h3>{editingIndex === null ? 'Add item' : 'Edit item'}</h3>
        <div className="fields">
          <label>
            Name
            <input
              value={draft.name}
              onChange={(event) => updateDraft('name', event.target.value)}
              required
            />
          </label>
          <label>
            Price
            <input
              type="number"
              min="0"
              step="0.01"
              value={draft.unitPrice}
              onChange={(event) => updateDraft('unitPrice', event.target.value)}
              required
            />
          </label>
          <label>
            Quantity
            <input
              type="number"
              min="0"
              step="1"
              value={draft.quantity}
              onChange={(event) => updateDraft('quantity', event.target.value)}
              required
            />
          </label>
          <label>
            Category
            <select
              value={draft.category}
              onChange={(event) => updateDraft('category', event.target.value)}
            >
              <option value="food">Food</option>
              <option value="beverage">Beverage</option>
              <option value="alcohol">Alcohol</option>
            </select>
          </label>
        </div>

        <fieldset>
          <legend>Shared by</legend>
          {seats.length === 0 ? (
            <p className="muted">No seats yet.</p>
          ) : (
            <div className="checks">
              {seats.map((seatId) => (
                <label key={seatId} className="check">
                  <input
                    type="checkbox"
                    checked={draft.sharedBy.includes(seatId)}
                    onChange={() => toggleSharedSeat(seatId)}
                  />
                  Seat {seatId}
                </label>
              ))}
            </div>
          )}
        </fieldset>

        {formMessage ? <p className="errors">{formMessage}</p> : null}

        <div className="actions">
          <button type="submit">{editingIndex === null ? 'Add item' : 'Save item'}</button>
          {editingIndex !== null ? (
            <button type="button" className="secondary" onClick={cancelEdit}>
              Cancel
            </button>
          ) : null}
        </div>
      </form>

      {items.length === 0 ? (
        <p className="muted">No items added yet.</p>
      ) : (
        <div className="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Item</th>
                <th>Price</th>
                <th>Qty</th>
                <th>Category</th>
                <th>Seats</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, index) => (
                <tr key={`${item.name}-${index}`}>
                  <td>{item.name}</td>
                  <td>{item.unitPrice}</td>
                  <td>{item.quantity}</td>
                  <td>{item.category}</td>
                  <td>{item.sharedBy.join(', ')}</td>
                  <td className="actions">
                    <button type="button" className="text-button" onClick={() => editItem(index)}>
                      Edit
                    </button>
                    <button type="button" className="text-button" onClick={() => removeItem(index)}>
                      Remove
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <form className="stack" onSubmit={calculateBill}>
        <label>
          Discount code
          <input
            value={code}
            onChange={(event) => setCode(event.target.value)}
            placeholder="HAPPY20 or TABLE500"
          />
        </label>
        <button type="submit" disabled={loading}>
          {loading ? 'Calculating bill...' : 'Calculate Bill'}
        </button>
        {loading ? <p className="muted">Calculating bill...</p> : null}
        {failure ? <p className="errors">{failure}</p> : null}
        {messages.length > 0 ? (
          <ul className="errors">
            {messages.map((line) => (
              <li key={`${line.field}-${line.message}`}>{line.message}</li>
            ))}
          </ul>
        ) : null}
      </form>
    </section>
  );
}
