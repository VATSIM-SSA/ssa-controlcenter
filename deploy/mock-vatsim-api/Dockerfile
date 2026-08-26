FROM python:3.13-alpine
WORKDIR /app
COPY server.py .
COPY fixtures/ fixtures/
EXPOSE 8000
CMD ["python3", "server.py"]
