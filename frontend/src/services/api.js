import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

export function splitBill(bill) {
  return api.post('/api/split', bill).then((response) => response.data);
}

export function queueKitchen(queue) {
  return api.post('/api/kitchen/queue', queue).then((response) => response.data);
}

export function readError(error) {
  const response = error.response;

  if (response && response.status === 422 && response.data && response.data.errors) {
    return {
      validation: response.data.errors,
      message: '',
    };
  }

  return {
    validation: null,
    message: 'The request failed. Check that the API is running and try again.',
  };
}

export function validationLines(errors) {
  if (!errors) {
    return [];
  }

  return Object.entries(errors).flatMap(([field, messages]) =>
    messages.map((message) => ({ field, message })),
  );
}
